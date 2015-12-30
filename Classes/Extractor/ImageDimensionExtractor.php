<?php
namespace AdGrafik\FalFtp\Extractor;


/**
 * Description of ImageDimensionExtractor
 *
 * @author Jonas Temmen <jonas.temmen@artundweise.de>
 */
class ImageDimensionExtractor implements \TYPO3\CMS\Core\Resource\Index\ExtractorInterface{
   
    /**
     * Returns an array of supported file types;
     * An empty array indicates all filetypes
     * 
     * Not used in core atm (T3 7.6.0)
     *
     * @return array
     */
    public function getFileTypeRestrictions(){
        return array();
    }


    /**
     * Get all supported DriverClasses
     *
     * Since some extractors may only work for local files, and other extractors
     * are especially made for grabbing data from remote.
     *
     * Returns array of string with driver names of Drivers which are supported,
     * If the driver did not register a name, it's the classname.
     * empty array indicates no restrictions
     *
     * @return array
     */
    public function getDriverRestrictions(){
        return array("FTP");
    }

    /**
     * Returns the data priority of the extraction Service.
     * Defines the precedence of Data if several extractors
     * extracted the same property.
     *
     * Should be between 1 and 100, 100 is more important than 1
     *
     * @return int
     */
    public function getPriority(){
        return 70;
    }

    /**
     * Returns the execution priority of the extraction Service
     * Should be between 1 and 100, 100 means runs as first service, 1 runs at last service
     *
     * @return int
     */
    public function getExecutionPriority(){
        return 10;
    }

    /**
     * Checks if the given file can be processed by this Extractor
     *
     * @param \TYPO3\CMS\Core\Resource\File $file
     * @return bool
     */
    public function canProcess(\TYPO3\CMS\Core\Resource\File $file){
        if($file->getType() == \TYPO3\CMS\Core\Resource\File::FILETYPE_IMAGE){
            try{
                $size=$this->getImageSize($file);
                if(is_array($size) && $size[0]>0 && $size[1]>0){
                    return true;
                }
            }catch(Exception $e){
                return false;
            }
        }
        return false;
    }

    /**
     * The actual processing TASK
     *
     * Should return an array with database properties for sys_file_metadata to write
     *
     * @param \TYPO3\CMS\Core\Resource\File $file
     * @param array $previousExtractedData optional, contains the array of already extracted data
     * @return array
     */
    public function extractMetaData(\TYPO3\CMS\Core\Resource\File $file, array $previousExtractedData = array()){
        
        $size=$this->getImageSize($file);
        if(is_array($size) && $size[0]>0 && $size[1]>0){
            return array("width"=>$size[0],"height"=>$size[1]);
        }
        return array();
    }
    
    /**
     * Return the size-array of an image returned by getimagesize
     *
     * @param \TYPO3\CMS\Core\Resource\File $file
     * @return array
     */
    private function getImageSize(\TYPO3\CMS\Core\Resource\File $file){
        $tmpLocalFile=$file->getForLocalProcessing();
        return getimagesize($tmpLocalFile);
    }
}
