def kraepelin_factors(y, wrong, skipped):
    if len(y) != 50:
        raise ValueError("Kraepelin requires exactly 50 columns")
    x = range(1, 51)
    sx, sy = sum(x), sum(y)
    b = (50 * sum(a * b for a, b in zip(x, y)) - sx * sy) / (50 * sum(a * a for a in x) - sx * sx)
    return {"panker": round(sy / 50, 3), "tianker": wrong + skipped,
            "hanker": round(b * 50, 3), "janker": max(y) - min(y)}


def kraepelin_score(bands, factor, group, value):
    for band in bands:
        if band["factor"] == factor and band["group"] == group:
            lo, hi = band["lo"], band["hi"]
            if (lo is None or value >= lo) and (hi is None or value <= hi):
                return band["score"]
    raise ValueError(f"No band for {factor}/{group}/{value}")
