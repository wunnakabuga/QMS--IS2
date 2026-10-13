#!/usr/bin/env python3
"""
Generate the simulated training dataset described in Chapter 3, Section 3.5.

1,000 records of queue observations are produced from a discrete-event
simulation of a multi-counter public service centre. Waiting time is not
drawn from a formula; it emerges from simulated arrivals and services, so
the relationship between the predictors and the target is non-linear and
noisy in the way a real queue is. This is what makes Random Forest
Regression an appropriate model rather than an unnecessarily complex one.

Output: ml/data/queue_dataset.csv
"""
import csv
import os
import random
from pathlib import Path

RNG = random.Random(20260921)          # fixed seed: the dataset is reproducible
N_RECORDS = 1000
OUT = Path(__file__).parent / "data" / "queue_dataset.csv"

SERVICE_TYPES = {
    # service type          mean service minutes, variability
    "ID Registration":    (6.0, 1.8),
    "Birth Certificate":  (7.5, 2.4),
    "Business Permit":    (9.0, 3.0),
    "Licence Renewal":    (5.0, 1.5),
}

# Relative arrival intensity by hour of the day (08:00 - 16:00).
HOURLY_INTENSITY = {
    8: 0.75, 9: 1.15, 10: 1.40, 11: 1.30,
    12: 0.70, 13: 0.60, 14: 1.05, 15: 0.90, 16: 0.55,
}


def simulate_session(service_type, hour):
    """Simulate one service session and return one observation."""
    mean_service, service_sd = SERVICE_TYPES[service_type]
    intensity = HOURLY_INTENSITY[hour]

    counters = RNG.choice([2, 2, 3, 3, 3, 4, 4, 5])

    # Arrivals per hour scale with intensity and the capacity on duty.
    capacity = (60.0 / mean_service) * counters
    arrival_rate = max(1.0, RNG.gauss(capacity * intensity * 0.80, capacity * 0.15))

    # Queue length observed at the moment the prediction is requested.
    # Drawn directly rather than from a utilisation formula, which becomes
    # unstable as utilisation approaches one and produces queue lengths no
    # public service centre would ever allow to form.
    utilisation = min(1.25, arrival_rate / max(1e-6, capacity))
    expected_queue = 2.0 + 9.0 * (utilisation ** 2.2)
    queue_length = max(0, int(round(RNG.gauss(expected_queue, expected_queue * 0.55))))
    queue_length = min(queue_length, 28)

    # Per-client service times actually drawn for the clients ahead.
    served = 0.0
    for _ in range(queue_length):
        s = RNG.gauss(mean_service, service_sd)
        # occasional long case: a document problem, a supervisor referral
        if RNG.random() < 0.07:
            s *= RNG.uniform(1.6, 2.6)
        served += max(0.8, s)

    wait = served / counters

    # Staff slow down slightly during the busiest hours and just after lunch.
    if intensity > 1.2:
        wait *= RNG.uniform(1.03, 1.16)
    if hour == 13:
        wait *= RNG.uniform(1.05, 1.25)

    # Observation noise.
    wait = max(0.0, wait + RNG.gauss(0, 0.9))

    avg_service_time = round(RNG.gauss(mean_service, service_sd * 0.35), 2)
    avg_service_time = max(1.0, avg_service_time)
    service_rate = round(60.0 / avg_service_time, 2)

    return {
        "queue_length": queue_length,
        "arrival_rate": round(arrival_rate, 2),
        "service_rate": service_rate,
        "avg_service_time": avg_service_time,
        "time_of_day": hour,
        "service_type": service_type,
        "open_counters": counters,
        "actual_wait_time": round(wait, 2),
    }


def main():
    OUT.parent.mkdir(parents=True, exist_ok=True)
    rows = []
    hours = list(HOURLY_INTENSITY.keys())
    types = list(SERVICE_TYPES.keys())
    for _ in range(N_RECORDS):
        rows.append(simulate_session(RNG.choice(types), RNG.choice(hours)))

    with OUT.open("w", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        writer.writeheader()
        writer.writerows(rows)

    waits = [r["actual_wait_time"] for r in rows]
    print(f"Wrote {len(rows)} records to {OUT}")
    print(f"  waiting time: min {min(waits):.1f}  mean "
          f"{sum(waits)/len(waits):.1f}  max {max(waits):.1f} minutes")


if __name__ == "__main__":
    main()
