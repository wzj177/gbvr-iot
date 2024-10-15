magick /Users/jiechengyang/src/www/webman-vr-panoramic/public/uploads/scene/2023/12/19/1702974122.jpg \
  -crop 416x416 -quality 95 \
  -set filename:tile "%[fx:page.x/416]_%[fx:page.y/416]" \
  -set filename:orig "%t" \
  "/Users/jiechengyang/src/www/webman-vr-panoramic/public/uploads/scene/test/cp_%[filename:tile].jpg"


#uploads/scene/2023/12/19/1702957879_p134_0_tiles