from pathlib import Path
import json,math
assets=Path(__file__).resolve().parents[1]/'public/assets'
source=(assets/'js/atlas-land.js').read_text()
land=json.loads(source.split('=',1)[1].rstrip(';\n'))
def project(lon,lat):
 p=math.radians(lat);q=math.radians(lon-45);t=math.radians(22)
 return 340+math.cos(p)*math.sin(q)*275,333-(math.sin(p)*math.cos(t)-math.cos(p)*math.cos(q)*math.sin(t))*275,math.sin(p)*math.sin(t)+math.cos(p)*math.cos(q)*math.cos(t)
def line(points,color,opacity,width):
 commands=[];connected=False
 for lon,lat in points:
  x,y,z=project(lon,lat)
  if z>.02:commands.append(('L' if connected else 'M')+f'{x:.1f} {y:.1f}');connected=True
  else:connected=False
 return f'<path d="{" ".join(commands)}" fill="none" stroke="{color}" opacity="{opacity}" stroke-width="{width}"/>'
svg=['<svg xmlns="http://www.w3.org/2000/svg" width="680" height="680" viewBox="0 0 680 680"><title>Earth coastlines and geographic grid</title><circle cx="340" cy="333" r="275" fill="#102138" fill-opacity=".15" stroke="#58a4ff" stroke-opacity=".22" stroke-width=".7"/>']
for lat in range(-60,61,30):svg.append(line([(lon,lat) for lon in range(-180,181,4)],'#72c5e6',.16,.6))
for lon in range(-180,180,30):svg.append(line([(lon,lat) for lat in range(-90,91,4)],'#72c5e6',.16,.6))
for coast in land['coasts']:svg.append(line(coast,'#72c5e6',.57,.75))
svg.append('<ellipse cx="340" cy="333" rx="310" ry="88" fill="none" stroke="#58a4ff" stroke-opacity=".18" stroke-width=".6" transform="rotate(-20 340 333)"/></svg>')
(assets/'img/atlas-earth-wire.svg').write_text(''.join(svg))
print('Wireframe SVG bytes', (assets/'img/atlas-earth-wire.svg').stat().st_size)
