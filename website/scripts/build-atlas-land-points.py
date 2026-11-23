"""Resample the existing licensed coast polygons into a finer, equal-area dot grid.

No external download is needed. Keep coasts intact, including their dateline wraps.
"""
import json
import math
from pathlib import Path

target = Path(__file__).resolve().parents[1] / 'public/assets/js/atlas-land.js'
source = target.read_text()
prefix, payload = source.split('=', 1)
land = json.loads(payload.rstrip(';\n'))
rings = []
for coast in land['coasts']:
    ring = [coast[0]]
    for lon, lat in coast[1:]:
        previous = ring[-1][0]
        ring.append([previous + (lon - previous + 180) % 360 - 180, lat])
    rings.append(ring)

points = []
spacing = 1.45
for row in range(math.ceil(140 / spacing)):
    lat = -56 + row * spacing
    # A scanline gives coast intersections without an expensive test for every dot.
    spans = []
    for ring in rings:
        crosses = sorted(a[0] + (lat - a[1]) * (b[0] - a[0]) / (b[1] - a[1])
                         for a, b in zip(ring, ring[1:]) if (a[1] > lat) != (b[1] > lat))
        spans.extend(zip(crosses[::2], crosses[1::2]))
    step = spacing / math.cos(math.radians(lat))
    lon = -180 + (row % 2) * step / 2
    while lon < 180:
        if any(left <= lon + wrap <= right for left, right in spans for wrap in (-360, 0, 360)):
            points.append([round(lon, 2), round(lat, 2)])
        lon += step
land['points'] = points
target.write_text(prefix + '=' + json.dumps(land, separators=(',', ':')) + ';\n')
print(f'{len(points)} land points at {spacing} degree spacing')
