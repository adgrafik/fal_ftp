<?php
namespace AdGrafik\FalFtp\FTPClient\Parser;

/***************************************************************
 * Copyright notice
 *
 * (c) 2014 Arno Dudek <webmaster@adgrafik.at>
 * All rights reserved
 * 
 * Parsing the list results was adapted from net2ftp by David Gartner.
 * @see https://www.net2ftp.com
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

use \AdGrafik\FalFtp\FTPClient\Parser\ParserInterface;
use \AdGrafik\FalFtp\FTPClient\FTPInterface;

// This class scans an ftp_rawlist line string and returns its parts (name, size,...).
// Adapted from net2ftp by David Gartner
// @see https://www.net2ftp.com

// ----------------------------------------------
// Sample FTP server's output
// ----------------------------------------------

// ---------------
// 1. "Standard" FTP servers output
// ---------------
// ftp.redhat.com
//drwxr-xr-x    6 0        0            4096 Aug 21  2001 pub (one or more spaces between entries)
//
// ftp.suse.com
//drwxr-xr-x   2 root     root         4096 Jan  9  2001 bin
//-rw-r--r--    1 suse     susewww       664 May 23 16:24 README.txt
//
// ftp.belnet.be
//-rw-r--r--   1 BELNET   Mirror        162 Aug  6  2000 HEADER.html
//drwxr-xr-x  53 BELNET   Archive      2048 Nov 13 12:03 mirror
//
// ftp.microsoft.com
//-r-xr-xr-x   1 owner    group               0 Nov 27  2000 dirmap.htm
//
// ftp.sourceforge.net
//-rw-r--r--   1 root     staff    29136068 Apr 21 22:07 ls-lR.gz
//
// ftp.nec.com
//dr-xr-xr-x  12 other        512 Apr  3  2002 pub
//
// ftp.intel.com
//drwxr-sr-x   11 root     ftp          4096 Sep 23 16:36 pub

// ---------------
// 3.1 Windows
// ---------------
//06-10-04  07:56PM                 8175 garantie.html
//04-09-04  04:27PM       <DIR>          images
//05-25-04  09:18AM                 9505 index.html

// ---------------
// 3.2 Netware
// ---------------
// total 0
// - [RWCEAFMS] USER 12 Mar 08 10:48 check.txt
// d [RWCEAFMS] USER 512 Mar 18 17:55 latest

// ---------------
// 3.3 AS400
// ---------------
// RGOVINDAN 932 03/29/01 14:59:53 *STMF /cert.txt
// QSYS 77824 12/17/01 15:33:14 *DIR /QOpenSys/
// QDOC 24576 12/31/69 20:00:00 *FLR /QDLS/
// QSYS 12832768 04/14/03 16:47:25 *LIB /QSYS.LIB/
// QDFTOWN 2147483647 12/31/69 20:00:00 *DDIR /QOPT/
// QSYS 2144 04/12/03 12:49:00 *DDIR /QFileSvr.400/
// QDFTOWN 1136 04/12/03 12:49:01 *DDIR /QNTC/

// ---------------
// 3.4 Titan FTP server
// ---------------
// total 6
// drwxrwx--- 1 owner group 512 Apr 19 11:44 .
// drwxrwx--- 1 owner group 512 Apr 19 11:44 ..
// -rw-rw---- 1 owner group 13171 Apr 15 13:50 default.asp
// drwxrwx--- 1 owner group 512 Apr 19 11:44 forum
// drwxrwx--- 1 owner group 512 Apr 15 13:32 images
// -rw-rw---- 1 owner group 764 Apr 15 11:07 styles.css

class StrictRulesParser implements ParserInterface {

	/**
	 * Parse the FTP result line.
	 *
	 * @param array &$resourceInfo
	 * @param string $resource
	 * @param \AdGrafik\FalFtp\FTPClient\FTPInterface $parentObject
	 * @return boolean
	 */
	public function parse(&$resourceInfo, $resource, FTPInterface $parentObject) {

		//              permissions              number      owner      group   size        month         day        year/hour    filename
#		Original regexp: '/([-dl])([rwxsStT-]{9})[ ]+([0-9]+)[ ]+([^ ]+)[ ]+(.+)[ ]+([0-9]+)[ ]+([a-zA-Z]+[ ]+[0-9]+)[ ]+([0-9:]+)[ ]+(.*)/'
		if (preg_match('/([-dl])([rwxsStT-]{9})\s+([0-9]+)\s+([^\s]+)\s+(.+)\s+([0-9]+)\s+([a-zA-Z]+\s+[0-9]+\s+[0-9:]+)\s+(.*)/', $resource, $matches)) {
			$resourceInfo['isDirectory']  = ($matches[1] === 'd');
			$resourceInfo['name']         = $matches[8];
			$resourceInfo['size']         = $matches[6];
			$resourceInfo['owner']        = $matches[4];
			$resourceInfo['group']        = trim($matches[5]);
			$resourceInfo['mode']         = $matches[2];
			$resourceInfo['mtime']        = $this->getTimestampOfDate($matches[7]);

			if ($resourceInfo['isDirectory'] === FALSE) {
				$resourceInfo['mimetype'] = $parentObject->getMimeType($resourceInfo['name']);
			}

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Parse the timestamp.
	 *
	 * @param string $date
	 * @return integer
	 */
	protected function getTimestampOfDate($date) {
		// Date format depends on what FTP server returns (year, month, day, hour, minutes... see above)
		// Set time always with seconds, else the seconds of the time NOW is taken, and the hash identifier will change.
		$date = (\DateTime::createFromFormat('M j H:i:s', $date . ':00') ?: \DateTime::createFromFormat('M j Y H:i:s', $date . ' 00:00:00')) ?: new \DateTime('@0');
		return $date->format('U');
	}

}

?>