<?php
namespace AdGrafik\FalFtp\Extractor;

/***************************************************************
 * Copyright notice
 *
 * (c) 2015 Jonas Temmen <jonas.temmen@artundweise.de>
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * A copy is found in the textfile GPL.txt and important notices to the license
 * from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Resource\Index\ExtractorInterface;
use TYPO3\CMS\Core\Resource;

/**
 * An Interface for MetaData extractors the FAL Indexer uses.
 *
 * @author Jonas Temmen <jonas.temmen@artundweise.de>
 */
class ImageDimensionExtractor implements ExtractorInterface {

	/**
	 * Returns an array of supported file types;
	 * An empty array indicates all filetypes
	 * 
	 * Not used in core atm (T3 7.6.0)
	 *
	 * @return array
	 */
	public function getFileTypeRestrictions() {
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
	public function getDriverRestrictions() {
		return array('FTP');
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
	public function getPriority() {
		return 70;
	}

	/**
	 * Returns the execution priority of the extraction Service
	 * Should be between 1 and 100, 100 means runs as first service, 1 runs at last service
	 *
	 * @return int
	 */
	public function getExecutionPriority() {
		return 10;
	}

	/**
	 * Checks if the given file can be processed by this Extractor
	 *
	 * @param \TYPO3\CMS\Core\Resource\File $file
	 * @return bool
	 */
	public function canProcess(Resource\File $file) {
		if ($file->getType() == Resource\File::FILETYPE_IMAGE) {
			try {
				$size = $this->getImageSize($file);
				if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
					return TRUE;
				}
			} catch(\Exception $e){
				return FALSE;
			}
		}
		return FALSE;
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
	public function extractMetaData(Resource\File $file, array $previousExtractedData = array()) {
		$size = $this->getImageSize($file);
		if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
			return array('width' => $size[0], 'height' => $size[1]);
		}
		return array();
	}

	/**
	 * Return the size-array of an image returned by getimagesize
	 *
	 * @param \TYPO3\CMS\Core\Resource\File $file
	 * @return array
	 */
	private function getImageSize(Resource\File $file) {
		$tmpLocalFile = $file->getForLocalProcessing();
		return getimagesize($tmpLocalFile);
	}
}
