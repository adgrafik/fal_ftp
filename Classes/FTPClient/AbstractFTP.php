<?php
namespace AdGrafik\FalFtp\FTPClient;

/***************************************************************
 * Copyright notice
 *
 * (c) 2014 Arno Dudek <webmaster@adgrafik.at>
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

use \TYPO3\CMS\Core\Utility\PathUtility;
use \TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractFTP implements \AdGrafik\FalFtp\FTPClient\FTPInterface {

	/**
	 * @var resource $stream
	 */
	protected $stream;

	/**
	 * @var \AdGrafik\FalFtp\FTPClient\ParserRegistry $parserRegistry
	 */
	protected $parserRegistry;

	/**
	 * @var \AdGrafik\FalFtp\FTPClient\ParserRegistry $parserRegistry
	 */
	protected $filterRegistry;

	/**
	 * Get parserRegistry
	 *
	 * @return \AdGrafik\FalFtp\FTPClient\ParserRegistry
	 */
	public function getParserRegistry() {
		return $this->parserRegistry;
	}

	/**
	 * Get filterRegistry
	 *
	 * @return \AdGrafik\FalFtp\FTPClient\ParserRegistry
	 */
	public function getFilterRegistry() {
		return $this->filterRegistry;
	}

	/**
	 * Get stream
	 *
	 * @return resource
	 */
	public function getStream() {
		return $this->stream;
	}

	/**
	 * Returns the mime type of given file extension.
	 *
	 * @param string $fileName
	 * @return string
	 */
	public function getMimeType($fileName) {

		$extension = strtolower(PathUtility::pathinfo($fileName, PATHINFO_EXTENSION));

		switch ($extension) {
			case 'ai':
			case 'eps':
			case 'ps':
				$mimeType = 'application/postscript'; break;
			case 'aif':
			case 'aifc':
			case 'aiff':
				$mimeType = 'audio/x-aiff'; break;
			case 'asc':
			case 'txt':
				$mimeType = 'text/plain'; break;
			case 'atom':
				$mimeType = 'application/atom+xml'; break;
			case 'au':
			case 'snd':
				$mimeType = 'audio/basic'; break;
			case 'avi':
				$mimeType = 'video/x-msvideo'; break;
			case 'bcpio':
				$mimeType = 'application/x-bcpio'; break;
			case 'bin':
			case 'class':
			case 'dll':
			case 'dmg':
			case 'dms':
			case 'exe':
			case 'lha':
			case 'lzh':
			case 'so':
				$mimeType = 'application/octet-stream'; break;
			case 'bmp':
				$mimeType = 'image/bmp'; break;
			case 'cdf':
			case 'nc':
				$mimeType = 'application/x-netcdf'; break;
			case 'cgm':
				$mimeType = 'image/cgm'; break;
			case 'cpio':
				$mimeType = 'application/x-cpio'; break;
			case 'cpt':
				$mimeType = 'application/mac-compactpro'; break;
			case 'csh':
				$mimeType = 'application/x-csh'; break;
			case 'css':
				$mimeType = 'text/css'; break;
			case 'dcr':
			case 'dir':
			case 'dxr':
				$mimeType = 'application/x-director'; break;
			case 'dif':
			case 'dv':
				$mimeType = 'video/x-dv'; break;
			case 'djv':
			case 'djvu':
				$mimeType = 'image/vnd.djvu'; break;
			case 'doc':
				$mimeType = 'application/msword'; break;
			case 'dtd':
				$mimeType = 'application/xml-dtd'; break;
			case 'dvi':
				$mimeType = 'application/x-dvi'; break;
			case 'etx':
				$mimeType = 'text/x-setext'; break;
			case 'ez':
				$mimeType = 'application/andrew-inset'; break;
			case 'gif':
				$mimeType = 'image/gif'; break;
			case 'gram':
				$mimeType = 'application/srgs'; break;
			case 'grxml':
				$mimeType = 'application/srgs+xml'; break;
			case 'gtar':
				$mimeType = 'application/x-gtar'; break;
			case 'hdf':
				$mimeType = 'application/x-hdf'; break;
			case 'hqx':
				$mimeType = 'application/mac-binhex40'; break;
			case 'htm':
			case 'html':
				$mimeType = 'text/html'; break;
			case 'ice':
				$mimeType = 'x-conference/x-cooltalk'; break;
			case 'ico':
				$mimeType = 'image/x-icon'; break;
			case 'ics':
			case 'ifb':
				$mimeType = 'text/calendar'; break;
			case 'ief':
				$mimeType = 'image/ief'; break;
			case 'iges':
			case 'igs':
				$mimeType = 'model/iges'; break;
			case 'jnlp':
				$mimeType = 'application/x-java-jnlp-file'; break;
			case 'jp2':
				$mimeType = 'image/jp2'; break;
			case 'jpe':
			case 'jpeg':
			case 'jpg':
				$mimeType = 'image/jpeg'; break;
			case 'js':
				$mimeType = 'application/x-javascript'; break;
			case 'kar':
			case 'mid':
			case 'midi':
				$mimeType = 'audio/midi'; break;
			case 'latex':
				$mimeType = 'application/x-latex'; break;
			case 'm3u':
				$mimeType = 'audio/x-mpegurl'; break;
			case 'm4a':
			case 'm4b':
			case 'm4p':
				$mimeType = 'audio/mp4a-latm'; break;
			case 'm4u':
			case 'mxu':
				$mimeType = 'video/vnd.mpegurl'; break;
			case 'm4v':
				$mimeType = 'video/x-m4v'; break;
			case 'mac':
			case 'pnt':
			case 'pntg':
				$mimeType = 'image/x-macpaint'; break;
			case 'man':
				$mimeType = 'application/x-troff-man'; break;
			case 'mathml':
				$mimeType = 'application/mathml+xml'; break;
			case 'me':
				$mimeType = 'application/x-troff-me'; break;
			case 'mesh':
			case 'msh':
			case 'silo':
				$mimeType = 'model/mesh'; break;
			case 'mif':
				$mimeType = 'application/vnd.mif'; break;
			case 'mov':
			case 'qt':
				$mimeType = 'video/quicktime'; break;
			case 'movie':
				$mimeType = 'video/x-sgi-movie'; break;
			case 'mp2':
			case 'mp3':
			case 'mpga':
				$mimeType = 'audio/mpeg'; break;
			case 'mp4':
				$mimeType = 'video/mp4'; break;
			case 'mpe':
			case 'mpeg':
			case 'mpg':
				$mimeType = 'video/mpeg'; break;
			case 'ms':
				$mimeType = 'application/x-troff-ms'; break;
			case 'oda':
				$mimeType = 'application/oda'; break;
			case 'ogg':
				$mimeType = 'application/ogg'; break;
			case 'pbm':
				$mimeType = 'image/x-portable-bitmap'; break;
			case 'pct':
			case 'pic':
			case 'pict':
				$mimeType = 'image/pict'; break;
			case 'pdb':
				$mimeType = 'chemical/x-pdb'; break;
			case 'pdf':
				$mimeType = 'application/pdf'; break;
			case 'pgm':
				$mimeType = 'image/x-portable-graymap'; break;
			case 'pgn':
				$mimeType = 'application/x-chess-pgn'; break;
			case 'png':
				$mimeType = 'image/png'; break;
			case 'pnm':
				$mimeType = 'image/x-portable-anymap'; break;
			case 'ppm':
				$mimeType = 'image/x-portable-pixmap'; break;
			case 'ppt':
				$mimeType = 'application/vnd.ms-powerpoint'; break;
			case 'qti':
			case 'qtif':
				$mimeType = 'image/x-quicktime'; break;
			case 'ra':
			case 'ram':
				$mimeType = 'audio/x-pn-realaudio'; break;
			case 'ras':
				$mimeType = 'image/x-cmu-raster'; break;
			case 'rdf':
				$mimeType = 'application/rdf+xml'; break;
			case 'rgb':
				$mimeType = 'image/x-rgb'; break;
			case 'rm':
				$mimeType = 'application/vnd.rn-realmedia'; break;
			case 'roff':
			case 't':
			case 'tr':
				$mimeType = 'application/x-troff'; break;
			case 'rtf':
				$mimeType = 'text/rtf'; break;
			case 'rtx':
				$mimeType = 'text/richtext'; break;
			case 'sgm':
			case 'sgml':
				$mimeType = 'text/sgml'; break;
			case 'sh':
				$mimeType = 'application/x-sh'; break;
			case 'shar':
				$mimeType = 'application/x-shar'; break;
			case 'sit':
				$mimeType = 'application/x-stuffit'; break;
			case 'skd':
			case 'skm':
			case 'skp':
			case 'skt':
				$mimeType = 'application/x-koan'; break;
			case 'smi':
			case 'smil':
				$mimeType = 'application/smil'; break;
			case 'spl':
				$mimeType = 'application/x-futuresplash'; break;
			case 'src':
				$mimeType = 'application/x-wais-source'; break;
			case 'sv4cpio':
				$mimeType = 'application/x-sv4cpio'; break;
			case 'sv4crc':
				$mimeType = 'application/x-sv4crc'; break;
			case 'svg':
				$mimeType = 'image/svg+xml'; break;
			case 'swf':
				$mimeType = 'application/x-shockwave-flash'; break;
			case 'tar':
				$mimeType = 'application/x-tar'; break;
			case 'tcl':
				$mimeType = 'application/x-tcl'; break;
			case 'tex':
				$mimeType = 'application/x-tex'; break;
			case 'texi':
			case 'texinfo':
				$mimeType = 'application/x-texinfo'; break;
			case 'tif':
			case 'tiff':
				$mimeType = 'image/tiff'; break;
			case 'tsv':
				$mimeType = 'text/tab-separated-values'; break;
			case 'ustar':
				$mimeType = 'application/x-ustar'; break;
			case 'vcd':
				$mimeType = 'application/x-cdlink'; break;
			case 'vrml':
			case 'wrl':
				$mimeType = 'model/vrml'; break;
			case 'vxml':
				$mimeType = 'application/voicexml+xml'; break;
			case 'wav':
				$mimeType = 'audio/x-wav'; break;
			case 'wbmp':
				$mimeType = 'image/vnd.wap.wbmp'; break;
			case 'wbmxl':
				$mimeType = 'application/vnd.wap.wbxml'; break;
			case 'wml':
				$mimeType = 'text/vnd.wap.wml'; break;
			case 'wmlc':
				$mimeType = 'application/vnd.wap.wmlc'; break;
			case 'wmls':
				$mimeType = 'text/vnd.wap.wmlscript'; break;
			case 'wmlsc':
				$mimeType = 'application/vnd.wap.wmlscriptc'; break;
			case 'xbm':
				$mimeType = 'image/x-xbitmap'; break;
			case 'xht':
			case 'xhtml':
				$mimeType = 'application/xhtml+xml'; break;
			case 'xls':
				$mimeType = 'application/vnd.ms-excel'; break;
			case 'xml':
			case 'xsl':
				$mimeType = 'application/xml'; break;
			case 'xpm':
				$mimeType = 'image/x-xpixmap'; break;
			case 'xslt':
				$mimeType = 'application/xslt+xml'; break;
			case 'xul':
				$mimeType = 'application/vnd.mozilla.xul+xml'; break;
			case 'xwd':
				$mimeType = 'image/x-xwindowdump'; break;
			case 'xyz':
				$mimeType = 'chemical/x-xyz'; break;
			case 'zip':
				$mimeType = 'application/zip'; break;
			default:
				$mimeType = 'application/octet-stream'; break;
		}

		return $mimeType;
	}

	/**
	 * Returns the absolute path of the FTP remote directory or file.
	 *
	 * @param string $relativeDirectoryOrFilePath
	 * @return string
	 */
	protected function getAbsolutePath($relativeDirectoryOrFilePath) {
		return $this->basePath . $relativeDirectoryOrFilePath;
	}

	/**
	 * Returns the identifier of the folder the file resides in
	 *
	 * @param string $directoryOrFile
	 * @return mixed
	 */
	protected function getParentDirectory($directoryOrFile) {
		$parentDirectory = PathUtility::dirname($directoryOrFile);
		if ($parentDirectory === '/') {
			return $parentDirectory;
		}
		return $parentDirectory . '/';
	}

	/**
	 * Returns the identifier of the folder the file resides in
	 *
	 * @param string $directoryOrFile
	 * @return mixed
	 */
	protected function getResourceName($directoryOrFile) {
		return trim(PathUtility::basename($directoryOrFile), '/');
	}

}

?>