<?php

/**
 * A class to process PDFs in the background so users don't have to wait when saving items/files
 *
 * @package PDFCreate
 * @author Joe Corall <jcorall@kent.edu>
 */

class PDFCreate_OCR extends Omeka_Job_AbstractJob
{
    private $_item;

    public function __construct(array $options)
    {
        $this->_item = get_record_by_id('Item', $options['item_id']);

        parent::__construct($options);
    }

    public function perform()
    {
        // keep track of all the OCR'd PDFs so if we need to create
        // the aggregated PDF we'll have all the files handy
        $ocr_pdfs = array();
        foreach ($this->_item->Files as $file) {

            // don't process if not a TIFF
            if ($file->mime_type !== 'image/tiff') {
                continue;
            }

            // if the directory doesn't exist to store all the PDFs, create it
            $ocr_dir = PDF_CREATE_OCR_DIR . DIRECTORY_SEPARATOR . $this->_item->id;
            if (!is_dir($ocr_dir)) {
                mkdir($ocr_dir);
            }

            // set $ocr_file to the original file name minus the ".tiff" extension
            // this way when tesseract runs the extension will be .pdf or .txt rather than .tiff.pdf/.tiff.txt
            $original_file = explode('.', $file->original_filename);
            $ocr_file = $ocr_dir . DIRECTORY_SEPARATOR . array_shift($original_file);

            // Extract OCR only if PDF file for this TIFF doesn't exist
            if (file_exists($ocr_file . '.pdf')) {
                $ocr_pdfs[] = $ocr_file . '.pdf';
                continue;
            }

            // if this file is being uploaded right now, the temp file will exist in the /tmp directory
            // otherwise the file will already have been moved into its production location
            // so setup the proper path
            $tmp_file = FILES_DIR . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . $file->filename;
            if (!file_exists($tmp_file)) {
                $tmp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $file->filename;
                if (!file_exists($tmp_file)) {
                    // @todo doesn't seem possible, but how to handle???
                }
            }

            // create the OCR'd PDF for this TIFF
            $cmd = "/usr/local/bin/tesseract -l eng -psm 3 $tmp_file $ocr_file pdf";
            exec($cmd);

            // get the OCR text for the TIFF and put it in a text file
            $cmd = "/usr/local/bin/tesseract -l eng -psm 3 $tmp_file $ocr_file";
            exec($cmd);

            // read in the OCR text and add it to the PdfText element
            $ocr_pdfs[] = $ocr_file . '.pdf';
            $ocr_file .= '.txt';
            if (file_exists($ocr_file)) {
                $ocr_txt = file_get_contents($ocr_file);
                if (strlen($ocr_txt)) {
                    $element = $file->getElement(PdfTextPlugin::ELEMENT_SET_NAME, PdfTextPlugin::ELEMENT_NAME);
                    $file->addTextForElement($element, $ocr_txt);
                    $file->save();
                    unlink($ocr_file);
                }
            }
        }


        // the name of the aggregated PDF File
        $pdf_file = PDF_CREATE_PDF_DIR . DIRECTORY_SEPARATOR . $this->_item->id . '.pdf';

        // if this item is public, make sure the aggregated PDF has been created
        if ($this->_item->public) {
            $metadata_file = $ocr_dir . DIRECTORY_SEPARATOR . 'metadata.txt';
            // this metadata.txt file is needed to create a valid PDF/a-1b document
            // so if it isn't there, add it.
            if (!file_exists($metadata_file)) {
                $f = fopen($metadata_file, 'w');
                fwrite($f, "[ /Title (");
                fwrite($f, metadata($this->_item, array('Dublin Core', 'Title')));
                fwrite($f, ")\n");
                fwrite($f, '/DOCINFO pdfmark');
                fclose($f);
            }

            // see if the aggregated PDF has already been created
            $pdf_exists = file_exists($pdf_file);
            // if it is already generated, see when it was last updated
            $pdf_created = $pdf_exists ? filemtime($pdf_file) : 0;

            // go through all the individual PDFs for all the files added to this item
            // see when the last PDF was updated
            // that way if a new file was added, we'll be sure to create the PDF again
            $last_ocr_edited = 0;
            foreach ($ocr_pdfs as $file) {
                $last_edited = filemtime($file);
                if ($last_edited > $last_ocr_edited) {
                    $last_ocr_edited = $last_edited;
                }
            }

            // if the aggregated PDF doesn't exist
            // OR a new PDF has been made since the aggregated PDF was created
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
        // else this item is not public
        // if this item is going from public to non-public, remove the file
        elseif (file_exists($pdf_file)) {
            unlink($pdf_file);
        }
    }
}
