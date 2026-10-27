# Parte la fila de Elena en dos mitades legibles (capítulo 4). Después de fase-a.cjs.
from PIL import Image
im = Image.open('capturas/fila.png'); w, h = im.size
cut = int(w * 0.485)
im.crop((0, 0, cut - 34, h - 52)).save('capturas/fila-a.png')
im.crop((cut - 10, 0, w, h)).save('capturas/fila-b.png')
print('fila-a y fila-b')
