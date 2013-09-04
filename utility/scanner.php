<?php

/**
 * ownCloud - Music app
 *
 * @author Morris Jobke
 * @copyright 2013 Morris Jobke <morris.jobke@gmail.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Music\Utility;

use \OCA\Music\BusinessLayer\ArtistBusinessLayer;
use \OCA\Music\BusinessLayer\AlbumBusinessLayer;
use \OCA\Music\BusinessLayer\TrackBusinessLayer;

use \OCA\AppFramework\Core\API;

class Scanner {

	private $api;
	private $extractor;
	private $artistBusinessLayer;
	private $albumBusinessLayer;
	private $trackBusinessLayer;

	public function __construct(API $api, Extractor $extractor, ArtistBusinessLayer $artistBusinessLayer,
		AlbumBusinessLayer $albumBusinessLayer, TrackBusinessLayer $trackBusinessLayer){
		$this->api = $api;
		$this->extractor = $extractor;
		$this->artistBusinessLayer = $artistBusinessLayer;
		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->trackBusinessLayer = $trackBusinessLayer;

		// Trying to enable stream support
		if(ini_get('allow_url_fopen') !== '1') {
			$this->api->log('allow_url_fopen is disabled. It is strongly advised to enable it in your php.ini', 'warn');
			@ini_set('allow_url_fopen', '1');
		}
	}

	/**
	 * Get called by 'post_write' hook (file creation, file update)
	 * @param string $path the path of the file
	 */
	public function update($path){
		// debug logging
		$this->api->log('update - '. $path , 'debug');

		$metadata = $this->api->getFileInfo($path);

		if($metadata === false) {
			$this->api->log('cannot determine metadata for path ' . $path, 'debug');
			return;
		}

		if(ini_get('allow_url_fopen')) {
			$fileInfo = $this->extractor->extract('oc://' . $this->api->getView()->getAbsolutePath($path));

			/*if(!array_key_exists('comments', $fileInfo)) {
				$this->api->log('"comments" is empty ' . $path . ' # ' . $this->api->getView()->getAbsolutePath($path), 'debug');
				return;
			}*/

			$userId = $this->api->getUserId();

			// artist
			$artist = array_key_exists('artist', $fileInfo['comments']) ? $fileInfo['comments']['artist'][0] : null;
			if($artist === null || $artist === ''){
				// fallback to "ownCloud unknown artist"
				$artist = 'ownCloud unknown artist';
			}

			$alternativeTrackNumber = null;
			// title
			$title = array_key_exists('title', $fileInfo['comments']) ? $fileInfo['comments']['title'][0] : null;
			if($title === null || $title === ''){
				// fallback to file name
				$title = $metadata['name'];
				if(preg_match('/^(\d+)\W*[.-]\W*(.*)/', $title, $matches) === 1) {
					$alternativeTrackNumber = $matches[1];
					if(preg_match('/(.*)(\.(mp3|ogg))$/', $matches[2], $titleMatches) === 1) {
						$title = $titleMatches[1];
					} else {
						$title = $matches[2];
					}
				}
			}

			// album
			$album = array_key_exists('album', $fileInfo['comments']) ? $fileInfo['comments']['album'][0] : null;
			if($album === null || $album === ''){
				// fallback to "ownCloud unknown album"
				$album = 'ownCloud unknown album';
			}

			// track number
			$trackNumber = array_key_exists('track_number', $fileInfo['comments']) ? $fileInfo['comments']['track_number'][0] : null;
			if($trackNumber === null && $alternativeTrackNumber !== null) {
				$trackNumber = $alternativeTrackNumber;
			}
			// convert track number '1/10' to '1'
			$tmp = explode('/', $trackNumber);
			$trackNumber = $tmp[0];

			$year = array_key_exists('year', $fileInfo['comments']) ? $fileInfo['comments']['year'][0] : null;
			$mimetype = $metadata['mimetype'];
			$fileId = $metadata['fileid'];

			// debug logging
			$this->api->log('extracted metadata - ' .
				sprintf('artist: %s, album: %s, title: %s, track#: %s, year: %s, mimetype: %s, fileId: %i',
					$artist, $album, $title, $trackNumber, $year, $mimetype, $fileId), 'debug');

			// add artist and get artist entity
			$artist = $this->artistBusinessLayer->addArtistIfNotExist($artist, $userId);
			$artistId = $artist->getId();

			// add album and get album entity
			$album = $this->albumBusinessLayer->addAlbumIfNotExist($album, $year, $artistId, $userId);
			$albumId = $album->getId();

			// add track and get track entity
			$track = $this->trackBusinessLayer->addTrackIfNotExist($title, $trackNumber, $artistId,
				$albumId, $fileId, $mimetype, $userId);

			// debug logging
			$this->api->log('imported entities - ' .
				sprintf('artist: %d, album: %d, track: %d', $artistId, $albumId, $track->getId()),
				'debug');
		}

	}

	/**
	 * Get called by 'delete' hook (file deletion)
	 * @param string $path the path of the file
	 */
	public function delete($path){
		// debug logging
		$this->api->log('delete - '. $path , 'debug');

		$metadata = $this->api->getFileInfo($path);
		$fileId = $metadata['fileid'];
		$userId = $this->api->getUserId();

		$remaining = $this->trackBusinessLayer->deleteTrack($fileId, $userId);

		$this->albumBusinessLayer->deleteById($remaining['albumIds']);
		$this->artistBusinessLayer->deleteById($remaining['artistIds']);

		// debug logging
		$this->api->log('removed entities - albums: [' . implode(',', $remaining ['albumIds']) .
			'], artists: [' . implode(',', $remaining['artistIds']) . ']' , 'debug');
	}

	/**
	 * Rescan the whole file base for new files
	 */
	public function rescan() {
		$music = $this->api->searchByMime('audio');
		$ogg = $this->api->searchByMime('application/ogg');
		$music = array_merge($music, $ogg);
		foreach ($music as $file) {
			$this->update($file['path']);
		}
	}
}