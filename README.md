# PDF Create

Omeka plugin to store OCR for uploaded TIFFs.

Generates OCR via tesseract.

Stores OCR'd text via PdfText plugin's metadata element for site searching.

Aggregates multiple TIFFs for one item into single OCR'd PDF/a-1b PDF via Ghostscript.

## Install

The server-side software needed to peform the OCR extraction is [Ghostscript](https://www.ghostscript.com/) and [Tesseract](https://github.com/tesseract-ocr/tesseract/blob/master/README.md). This is the exact versions of the required software verified to work with this plugin (running on Red Hat Enterprise Linux 7):

* GPL Ghostscript 9.07 (2013-02-14)
* [Tesseract 3.04.01](https://github.com/tesseract-ocr/tesseract/releases/tag/3.04.01)
  * [leptonica 1.73](http://www.leptonica.com/download.html)
    * libjpeg 6b (libjpeg-turbo 1.2.90)
      * libpng 1.5.13
      * libtiff 4.0.3
      * zlib 1.2.7
* Download the [tessdata 3.04.00](https://github.com/tesseract-ocr/tessdata/releases/tag/3.04.00) tarball
  * mv all eng.* files to /usr/local/share/tessdata/
* Download the file "pdf.ttf" [found here](http://bugs.ghostscript.com/show_bug.cgi?id=695869#c25) to /usr/local/share/tessdata/
  * Without this updated pdf.ttf when two or more PDFs are aggregated into a single PDF via Ghostscript the resulting OCR will have spaces between every letter, essentially ruining the OCR. Essentially the tesseract and ghostscript fonts don't map perfectly, but this file fixes that.
