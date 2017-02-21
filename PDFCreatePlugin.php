<?php

/**
 * @author  Joe Corall <jcorall@kent.edu>
 *
 * @todo integrate with Google’s Cloud Vision API
 * @todo batch hook operations to speed up file uploads
 * @todo document software requirements/setup
 * @todo smarter checking to regenerate OCR/PDF on edits
 * @todo add link to PDF in Files
 * @todo cleanup on file/item delete
 */

class PDFCreatePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'after_save_item',
        'before_save_file',
        'install',
    );

    public function hookInstall() {
        mkdir(FILES_DIR . DIRECTORY_SEPARATOR . 'ocr');
        mkdir(FILES_DIR . DIRECTORY_SEPARATOR . 'pdfs');
    }

    public function hookBeforeSaveFile($args)
    {
        $file = $args['record'];
        // don't process if not a TIFF
        if (!in_array($file->mime_type, array('image/tiff'))) {
            return;
        }

        // make the directory the item ID and create it if it doesn't exist
        $ocr_dir = FILES_DIR . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . $file->item_id;
        if (!is_dir($ocr_dir)) {
            mkdir($ocr_dir);
        }

        // set $ocr_file to the original file name minus the ".tiff" extension
        // this way when tesseract runs the extension will be .pdf or .txt rather than .tiff.pdf/.tiff.txt
        $original_file = explode('.', $file->original_filename);
        $ocr_file = $ocr_dir . DIRECTORY_SEPARATOR . array_shift($original_file);

        // Extract OCR only on file insert OR if the PDF doesn't exist
        if (empty($args['insert']) && file_exists($ocr_file . '.pdf')) {
            return;
        }

        // if this file is being uploaded right now, the temp file will exist in the /tmp directory
        // otherwise the file will already have been moved into its production location
        // so setup the proper path
        $tmp_file = empty($args['insert']) ? FILES_DIR . DIRECTORY_SEPARATOR . 'original' : sys_get_temp_dir();
        $tmp_file .= DIRECTORY_SEPARATOR . $file->filename;

        // create the OCR'd PDF
        $cmd = "/usr/local/bin/tesseract -l eng -psm 3 $tmp_file $ocr_file pdf";
        exec($cmd);

        // get the OCR and put it in a text file
        $cmd = "/usr/local/bin/tesseract -l eng -psm 3 $tmp_file $ocr_file";
        exec($cmd);

        // read in the OCR text and add it to the PdfText element
        $ocr_file .= '.txt';
        if (file_exists($ocr_file)) {
            $ocr_txt = file_get_contents($ocr_file);
            if (strlen($ocr_txt)) {
                $element = $file->getElement(PdfTextPlugin::ELEMENT_SET_NAME, PdfTextPlugin::ELEMENT_NAME);
                $file->addTextForElement($element, $ocr_txt);
                unlink($ocr_file);
            }
        }
    }

    public function hookAfterSaveItem($args)
    {
        $item = $args['record'];

        if ($item->public) {
            $ocr_dir = FILES_DIR . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . $item->id;
            $metadata_file = $ocr_dir . DIRECTORY_SEPARATOR . 'metadata.txt';
            // this metadata.txt file is needed to create a valid PDF/a-1b document
            // so if it isn't there, add it.
            if (!file_exists($metadata_file)) {
                $f = fopen($metadata_file, 'w');
                fwrite($f, "[ /Title (".metadata($item, array('Dublin Core', 'Title')).")\n");
                fwrite($f, '/DOCINFO pdfmark');
                fclose($f);
            }

            // set the PDF's filename to the item ID and store it in the "pdfs" directory
            $pdf_file = FILES_DIR . DIRECTORY_SEPARATOR . 'pdfs' . DIRECTORY_SEPARATOR . $item->id . '.pdf';

            // see if the PDF is already generated
            $pdf_exists = file_exists($pdf_file);
            // if it is already generated, see when it was last updated
            $pdf_created = $pdf_exists ? filemtime($pdf_file) : 0;

            // go through all the individual PDFs for all the files added to this item
            // see when the last PDF was updated
            // that way if a new file was added, we'll be sure to create the PDF again
            $last_ocr_edited = 0;
            $ocr_pdfs = array();
            foreach (scandir($ocr_dir) as $file) {
                // if it's not a PDF, skip it
                if (substr($file, -3) !== 'pdf') {
                    continue;
                }
                $file = $ocr_dir . DIRECTORY_SEPARATOR . $file;
                // keep a list of all the PDFs to use in the ghostscript command below
                $ocr_pdfs[] = $file;

                $last_edited = filemtime($file);
                if ($last_edited > $last_ocr_edited) {
                    $last_ocr_edited = $last_edited;
                }
            }

            // if the aggregated PDF doesn't exist OR a new PDF has been made since it was created
            // generate the PDF
            if (!$pdf_exists || $pdf_created < $last_ocr_edited) {
                $cmd = "/usr/bin/gs -dBATCH \
                    -dNOPAUSE \
                    -dQUIET \
                    -sDEVICE=pdfwrite \
                    -dPDFA \
                    -sProcessColorModel=DeviceRGB \
                    -dUseCIEColor \
                    -dNOOUTERSAVE \
                    -dAutoRotatePages=/None \
                    -sPDFACompatibilityPolicy=1 \
                    -sOutputFile=$pdf_file \
                    " . implode(' ', $ocr_pdfs) . " \
                    $metadata_file";
                exec($cmd);
            }
        }
    }
}
