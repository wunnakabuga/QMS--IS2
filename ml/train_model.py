#!/usr/bin/env python3
"""
Train the Random Forest Regression model that predicts client waiting time.

Follows the model selection justified in Chapter 2, Section 2.3.5 and the
five predictor features specified in Chapter 4, Table 4.7:

    queue_length, arrival_rate, service_rate, avg_service_time, time_of_day

The fitted model is serialised to ml/model/rf_model.joblib, which is what
the PHP prediction module loads at runtime through ml/predict.py.

A held-out test partition is retained so that the accuracy figures
reported in Chapter 5 are computed on data the model has never seen.
"""
import json
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
from sklearn.dummy import DummyRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import train_test_split, cross_val_score
import joblib

BASE = Path(__file__).parent
DATA = BASE / "data" / "queue_dataset.csv"
MODEL_DIR = BASE / "model"
MODEL_FILE = MODEL_DIR / "rf_model.joblib"
METRICS_FILE = MODEL_DIR / "training_metrics.json"

FEATURES = ["queue_length", "arrival_rate", "service_rate",
            "avg_service_time", "time_of_day"]
TARGET = "actual_wait_time"
RANDOM_STATE = 42


FOREST_FILE = MODEL_DIR / "forest.npz"


def export_forest(model, features) -> None:
    """Flatten every decision tree in the forest into concatenated arrays.

    Each tree contributes its children_left, children_right, feature,
    threshold and leaf-value arrays. An offsets array records where each
    tree begins, so the whole forest lives in five flat arrays that numpy
    can walk without scikit-learn being present.
    """
    left, right, feat, thresh, value, offsets = [], [], [], [], [], [0]

    for estimator in model.estimators_:
        tree = estimator.tree_
        left.append(tree.children_left.astype(np.int32))
        right.append(tree.children_right.astype(np.int32))
        feat.append(tree.feature.astype(np.int32))
        thresh.append(tree.threshold.astype(np.float64))
        value.append(tree.value.reshape(-1).astype(np.float64))
        offsets.append(offsets[-1] + tree.node_count)

    np.savez_compressed(
        FOREST_FILE,
        children_left=np.concatenate(left),
        children_right=np.concatenate(right),
        feature=np.concatenate(feat),
        threshold=np.concatenate(thresh),
        value=np.concatenate(value),
        offsets=np.asarray(offsets, dtype=np.int64),
        features=np.asarray(features, dtype=object),
    )
    size_kb = FOREST_FILE.stat().st_size / 1024
    print(f"\nForest exported to {FOREST_FILE.name} ({size_kb:.0f} KB, "
          f"{len(model.estimators_)} trees)")


def predict_with_export(matrix):
    """Evaluate the exported forest using numpy only — the runtime path."""
    data = np.load(FOREST_FILE, allow_pickle=True)
    cl, cr = data["children_left"], data["children_right"]
    feat, thresh, value = data["feature"], data["threshold"], data["value"]
    offsets = data["offsets"]

    # scikit-learn casts the feature matrix to float32 before walking a tree,
    # so the threshold comparisons must be made at float32 precision too.
    # Comparing in float64 routes samples that sit exactly on a split
    # boundary down the opposite branch, which is how this reimplementation
    # first disagreed with the trained model.
    matrix = np.asarray(matrix, dtype=np.float32).astype(np.float64)
    totals = np.zeros(len(matrix), dtype=np.float64)
    n_trees = len(offsets) - 1

    for t in range(n_trees):
        base = offsets[t]
        node = np.zeros(len(matrix), dtype=np.int64)     # start at the root
        active = np.ones(len(matrix), dtype=bool)

        while active.any():
            idx = base + node[active]
            go_left = matrix[active, feat[idx]] <= thresh[idx]
            node[active] = np.where(go_left, cl[idx], cr[idx])
            active[active] = cl[base + node[active]] != -1

        totals += value[base + node]

    return totals / n_trees


def verify_export(model, X_test) -> None:
    """Confirm the exported forest reproduces scikit-learn's own output."""
    reference = model.predict(X_test)
    exported = predict_with_export(np.asarray(X_test, dtype=np.float64))
    worst = float(np.max(np.abs(reference - exported)))

    print(f"Export verification: largest difference from scikit-learn "
          f"across {len(X_test)} test rows = {worst:.2e} minutes")
    if worst > 1e-6:
        raise SystemExit(
            "Exported forest does not match scikit-learn. Aborting so that "
            "the runtime model cannot silently diverge from the trained one."
        )
    print("Exported forest matches the trained model.")


def main():
    if not DATA.exists():
        raise SystemExit(f"Dataset not found at {DATA}. "
                         f"Run generate_dataset.py first.")

    df = pd.read_csv(DATA)
    print(f"Loaded {len(df)} records from {DATA.name}")

    X = df[FEATURES]
    y = df[TARGET]

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=RANDOM_STATE
    )
    print(f"Training partition: {len(X_train)}   test partition: {len(X_test)}")

    model = RandomForestRegressor(
        n_estimators=300,
        max_depth=None,
        min_samples_leaf=2,
        max_features="sqrt",
        random_state=RANDOM_STATE,
        n_jobs=-1,
    )
    model.fit(X_train, y_train)

    pred = model.predict(X_test)
    mae = mean_absolute_error(y_test, pred)
    rmse = float(np.sqrt(mean_squared_error(y_test, pred)))
    r2 = r2_score(y_test, pred)

    # Cross-validated MAE on the training partition.
    cv = -cross_val_score(model, X_train, y_train, cv=5,
                          scoring="neg_mean_absolute_error")

    # Baseline: predicting the mean. Shows the model is learning something.
    baseline = DummyRegressor(strategy="mean").fit(X_train, y_train)
    base_mae = mean_absolute_error(y_test, baseline.predict(X_test))

    # Accuracy expressed as the share of predictions within 5 minutes,
    # which is the tolerance used for the usability claim in Chapter 5.
    within_5 = float(np.mean(np.abs(pred - y_test) <= 5.0) * 100)

    print("\nHeld-out test performance")
    print(f"  MAE                 {mae:6.2f} minutes")
    print(f"  RMSE                {rmse:6.2f} minutes")
    print(f"  R-squared           {r2:6.3f}")
    print(f"  Within 5 minutes    {within_5:6.1f} %")
    print(f"  Baseline (mean) MAE {base_mae:6.2f} minutes")
    print(f"  5-fold CV MAE       {cv.mean():6.2f} (+/- {cv.std():.2f})")

    print("\nFeature importance")
    for name, imp in sorted(zip(FEATURES, model.feature_importances_),
                            key=lambda kv: kv[1], reverse=True):
        print(f"  {name:<18} {imp:6.3f}")

    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    joblib.dump({"model": model, "features": FEATURES}, MODEL_FILE)

    # Also export the forest in a compact array form that can be evaluated
    # with numpy alone. Importing scikit-learn costs roughly 0.75 seconds of
    # interpreter start-up, and the prediction module runs on every queue
    # event, which would breach the one-second budget set by NFR-02. The
    # exported form is used at runtime; this joblib file remains the
    # reference model for retraining and inspection.
    export_forest(model, FEATURES)

    verify_export(model, X_test)

    metrics = {
        "records": int(len(df)),
        "train_size": int(len(X_train)),
        "test_size": int(len(X_test)),
        "mae_minutes": round(float(mae), 3),
        "rmse_minutes": round(rmse, 3),
        "r2": round(float(r2), 4),
        "within_5_minutes_percent": round(within_5, 2),
        "baseline_mae_minutes": round(float(base_mae), 3),
        "cv_mae_mean": round(float(cv.mean()), 3),
        "cv_mae_std": round(float(cv.std()), 3),
        "feature_importance": {
            f: round(float(i), 4)
            for f, i in zip(FEATURES, model.feature_importances_)
        },
    }
    METRICS_FILE.write_text(json.dumps(metrics, indent=2))

    print(f"\nModel written to   {MODEL_FILE}")
    print(f"Metrics written to {METRICS_FILE}")


if __name__ == "__main__":
    main()
