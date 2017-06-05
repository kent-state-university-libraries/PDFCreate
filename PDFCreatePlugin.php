<?php

/**
 * @author  Joe Corall <jcorall@kent.edu>
 *
 * @todo integrate with Google’s Cloud Vision API to allow alternative to tesseract req
 * @todo add link to PDF in Files
 * @todo cleanup on file/item delete
 * @todo allow admin to specify criteria on what TIFFs to create PDFs from based on a metadata search
 */

define('PDF_CREATE_OCR_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'ocr');
define('PDF_CREATE_PDF_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'pdfs' );

class PDFCreatePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
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
}
