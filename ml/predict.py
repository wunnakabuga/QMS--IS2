#!/usr/bin/env python3
"""
Prediction bridge between the PHP application and the trained model.

Reads a JSON object on stdin:

    {"rows": [{"queue_length": 3, "arrival_rate": 12.0, "service_rate": 10.0,
               "avg_service_time": 6.0, "time_of_day": 10}, ...]}

and writes a JSON object on stdout:

    {"predictions": [18.4, ...], "path": "fast"}

Two evaluation paths exist:

  fast    Loads model/forest.npz and walks the decision trees with numpy
          alone. Importing scikit-learn costs about 0.75 seconds of
          interpreter start-up, and this script runs on every queue event,
          so the fast path is what keeps the round trip inside the
          one-second budget set by NFR-02.

  sklearn Loads model/rf_model.joblib and calls the fitted estimator.
          Used only when the exported forest is absent. Correct but slow.

train_model.py verifies after every training run that the two paths agree
to within 1e-6 minutes, so the fast path cannot silently drift from the
trained model.

Any failure produces {"error": "..."} and no predictions, which causes the
PHP module to fall back to the deterministic estimate (FR-06).
"""
import json
import sys
from pathlib import Path

MODEL_DIR = Path(__file__).parent / "model"
FOREST_FILE = MODEL_DIR / "forest.npz"
MODEL_FILE = MODEL_DIR / "rf_model.joblib"


def predict_fast(rows):
    """Walk the exported forest with numpy only."""
    import numpy as np

    data = np.load(FOREST_FILE, allow_pickle=True)
    features = [str(f) for f in data["features"]]

    matrix = []
    for row in rows:
        matrix.append([float(row[column]) for column in features])

    # scikit-learn compares thresholds at float32 precision; match it so that
    # samples sitting exactly on a split boundary route the same way.
    matrix = np.asarray(matrix, dtype=np.float32).astype(np.float64)

    cl, cr = data["children_left"], data["children_right"]
    feat, thresh, value = data["feature"], data["threshold"], data["value"]
    offsets = data["offsets"]

    totals = np.zeros(len(matrix), dtype=np.float64)
    n_trees = len(offsets) - 1

    for t in range(n_trees):
        base = offsets[t]
        node = np.zeros(len(matrix), dtype=np.int64)
        # A tree whose root is already a leaf has nothing to walk.
        active = np.full(len(matrix), bool(cl[base] != -1), dtype=bool)

        while active.any():
            idx = base + node[active]
            go_left = matrix[active, feat[idx]] <= thresh[idx]
            node[active] = np.where(go_left, cl[idx], cr[idx])
            active[active] = cl[base + node[active]] != -1

        totals += value[base + node]

    return totals / n_trees


def predict_reference(rows):
    """Evaluate through scikit-learn itself."""
    import warnings

    import joblib
    import numpy as np

    bundle = joblib.load(MODEL_FILE)
    model = bundle["model"]
    features = bundle["features"]

    matrix = [[float(row[column]) for column in features] for row in rows]

    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        return model.predict(np.asarray(matrix, dtype=np.float64))


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        rows = payload.get("rows") or []
        if not rows:
            print(json.dumps({"predictions": []}))
            return 0

        if FOREST_FILE.exists():
            predictions, path = predict_fast(rows), "fast"
        elif MODEL_FILE.exists():
            predictions, path = predict_reference(rows), "sklearn"
        else:
            print(json.dumps({"error": "model not trained"}))
            return 1

        print(json.dumps({
            "predictions": [round(float(max(0.0, p)), 2) for p in predictions],
            "path": path,
        }))
        return 0

    except KeyError as missing:
        print(json.dumps({"error": f"missing feature {missing}"}))
        return 1
    except Exception as exc:                      # noqa: BLE001
        print(json.dumps({"error": str(exc)}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
