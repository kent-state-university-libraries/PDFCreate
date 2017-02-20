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
        if (!in_array($file->mime_type, array('image/tiff'))) {
            return;
        }
        $ocr_dir = FILES_DIR . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . $file->item_id;
        if (!is_dir($ocr_dir)) {
            mkdir($ocr_dir);
        }
        $original_file = explode('.', $file->original_filename);
        $ocr_file = $ocr_dir . DIRECTORY_SEPARATOR . array_shift($original_file);

        // Extract OCR only on file insert.
        if (empty($args['insert']) && file_exists($ocr_file . '.pdf')) {
            return;
        }

        $tmp_file = empty($args['insert']) ? FILES_DIR . DIRECTORY_SEPARATOR . 'original' : sys_get_temp_dir();
        $tmp_file .= DIRECTORY_SEPARATOR . $file->filename;

        $cmd = "/usr/local/bin/tesseract -l eng -psm 3 $tmp_file $ocr_file pdf";
        exec($cmd);

        $cmd = "/usr/local/bin/tesseract -l eng -psm 3 $tmp_file $ocr_file";
        exec($cmd);

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
            if (!file_exists($metadata_file)) {
                $f = fopen($metadata_file, 'w');
                fwrite($f, "[ /Title (".metadata($item, array('Dublin Core', 'Title')).")\n");
                fwrite($f, '/DOCINFO pdfmark');
                fclose($f);
            }

            $pdf_file = FILES_DIR . DIRECTORY_SEPARATOR . 'pdfs' . DIRECTORY_SEPARATOR . $item->id . '.pdf';
            $pdf_exists = file_exists($pdf_file);
            $pdf_created = $pdf_exists ? filemtime($pdf_file) : 0;
            $last_ocr_edited = 0;
            $ocr_pdfs = array();
            foreach (scandir($ocr_dir) as $file) {
                if (substr($file, -3) !== 'pdf') {
                    continue;
                }
                $file = $ocr_dir . DIRECTORY_SEPARATOR . $file;
                $ocr_pdfs[] = $file;
                if (is_file($file) && filemtime($file) > $last_ocr_edited) {
                    $last_ocr_edited = filemtime($file);
                }
            }
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
