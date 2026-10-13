"""Build locale-first SVG posters from the existing, licensed Natural Earth geometry."""
from pathlib import Path
import json,math
assets=Path(__file__).resolve().parents[1]/'public/assets'
land=json.loads((assets/'js/atlas-land.js').read_text().split('=',1)[1].rstrip(';\n'))
for code,yaw in [('us',-98),('tr',35)]:
 def project(lon,lat):
  p=math.radians(lat);q=math.radians(lon-yaw);t=math.radians(22)
  return 340+math.cos(p)*math.sin(q)*255,330-(math.sin(p)*math.cos(t)-math.cos(p)*math.cos(q)*math.sin(t))*255,math.sin(p)*math.sin(t)+math.cos(p)*math.cos(q)*math.cos(t)
 svg=['<svg xmlns="http://www.w3.org/2000/svg" width="680" height="680" viewBox="0 0 680 680"><title>Earth geographic illustration</title><defs><radialGradient id="planet"><stop stop-color="#11253b"/><stop offset="1" stop-color="#08121e"/></radialGradient></defs><circle cx="340" cy="330" r="255" fill="url(#planet)" stroke="#325172" stroke-width=".8"/>']
 for lon,lat in land['points']:
  x,y,z=project(lon,lat)
  if z>.02:svg.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="1.2" fill="#75c9eb" opacity="{.15+.75*z:.2f}"/>')
 svg.append('<ellipse cx="340" cy="330" rx="286" ry="81" fill="none" stroke="#5aa4db" stroke-width=".7" opacity=".3" transform="rotate(-20 340 330)"/></svg>')
 (assets/f'img/atlas-earth-{code}.svg').write_text(''.join(svg))
