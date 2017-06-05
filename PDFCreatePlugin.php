<?php

/**
 * @author  Joe Corall <jcorall@kent.edu>
 *
 * @todo integrate with Google’s Cloud Vision API to allow alternative to tesseract req
 * @todo add link to PDF in Files
 * @todo allow admin to specify criteria on what TIFFs to create PDFs from based on a metadata search
 */

define('PDF_CREATE_OCR_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'ocr');
define('PDF_CREATE_PDF_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'pdfs' );

class PDFCreatePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'after_delete_item',
        'after_delete_file',
        'after_save_item',
        'install',
    );

    public function hookInstall() {
        mkdir(PDF_CREATE_OCR_DIR);
        mkdir(PDF_CREATE_PDF_DIR);
    }

    public function hookAfterSaveItem($args)
    {
        $item = $args['record'];

        $jobDispatcher = Zend_Registry::get('job_dispatcher');
        $jobDispatcher->setQueueName('pdfcreate_ocr');
        try {
            $options = array(
                'item_id' => $item->id
            );

            $jobDispatcher->sendLongRunning('PDFCreate_OCR', $options);

        } catch (Exception $e) {
            throw $e;
        }
    }
    public function hookAfterDeleteFile($args)
    {
        $file = $args['record'];
        $ocr_dir = PDF_CREATE_OCR_DIR . DIRECTORY_SEPARATOR . $file->item_id;
        $original_file = explode('.', $file->original_filename);
        $ocr_file = $ocr_dir . DIRECTORY_SEPARATOR . array_shift($original_file) . '.pdf';
        if (file_exists($ocr_file)) {
            unlink($ocr_file);
            $pdf = PDF_CREATE_PDF_DIR . DIRECTORY_SEPARATOR . $file->item_id . '.pdf';
            if (file_exists($pdf)) {
                unlink($pdf);
            }
        }
    }
    public function hookAfterDeleteItem($args)
    {
        $item = $args['record'];

        // when deleting an item remove all the OCR'd PDFs and the directory that stored them
        $ocr_dir = PDF_CREATE_OCR_DIR . DIRECTORY_SEPARATOR . $item->id;
        if (file_exists($ocr_dir)) {
            array_map('unlink', glob("$ocr_dir/*.*"));
            rmdir($ocr_dir);

            // also remove the aggregated PDF if it exists
            $pdf = PDF_CREATE_PDF_DIR . DIRECTORY_SEPARATOR . $item->id . '.pdf';
            if (file_exists($pdf)) {
                unlink($pdf);
            }
        }
    }
}
