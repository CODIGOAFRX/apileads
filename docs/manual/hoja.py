import pymupdf,sys
names=sys.argv[2:]; out=sys.argv[1]
doc=pymupdf.open(); page=doc.new_page(width=1400,height=2000); x=10;y=10;colh=0
for n in names:
    p='capturas/'+n+'.png'; pix=pymupdf.Pixmap(p); w=680; h=pix.height*w/pix.width
    if h>600: w=w*600/h; h=600
    if x+w>1400: x=10; y+=colh+24; colh=0
    page.insert_image(pymupdf.Rect(x,y,x+w,y+h),filename=p); page.insert_text((x+2,y+h+12),n,fontsize=11)
    x+=w+20; colh=max(colh,h)
page.get_pixmap(dpi=72,clip=pymupdf.Rect(0,0,1400,min(2000,y+colh+30))).save(out)
