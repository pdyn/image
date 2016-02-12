<?php
/*
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * @copyright 2010 onwards James McQuillan (http://pdyn.net)
 * @author James McQuillan <james@pdyn.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pdyn\image;

/**
 * General image manipulation library.
 */
class Image {
	/** Error code indicating file was not found. */
	const ERR_FILE_NOT_FOUND = 404;

	/** Error code indicating file was found but is not supported. */
	const ERR_FILETYPE_NOT_SUPPORTED = 501;

	/** Error code indicating some input was invalid. */
	const ERR_BAD_INPUT = 400;

	/** Used as the $dir input for $this->flip() to flip an image horizontally. */
	const IMG_FLIP_HORIZONTAL = 1;

	/** Used as the $dir input for $this->flip() to flip an image vertically. */
	const IMG_FLIP_VERTICAL = 2;

	/** Used as the $dir input for $this->flip() to flip an image both horizontally and vertically. */
	const IMG_FLIP_BOTH = 3;

	/** The number of degrees to rotate an image 90 clockwise. */
	const ROTATE_CW = 270;

	/** The number of degrees to rotate an image 90 counterclockwise. */
	const ROTATE_CCW = 90;

	/** @var string The absolute path to the currently loaded image. */
	protected $filename;

	/** @var resource The GD image resource for the currently loaded image. */
	protected $res;

	/** @var string The mime type of the currently loaded image. */
	protected $mime;

	/** @var array Exif data for the currently loaded image. Only set if image is a JPEG. */
	protected $exif;

	/** @var \Psr\Log\LoggerInterface A logging object to log to (if set). */
	protected $logger = null;

	/**
	 * Constructor.
	 *
	 * @param string $file The absolute path to an image file.
	 * @param bool $loadexif Whether to load exif information (can cause memory problems with large files).
	 */
	public function __construct($file, $loadexif = true) {
		if (is_string($file)) {
			$this->load_from_file($file, $loadexif);
		} elseif ($file instanceof Image) {
			$this->load_from_object($file, $loadexif);
		} else {
			throw new \Exception('$file must be filename or Image object.', static::ERR_BAD_INPUT);
		}
	}

	/**
	 * Set the logger to be used with the driver.
	 *
	 * @param \Psr\Log\LoggerInterface $logger A logging object to log to.
	 */
	public function set_logger(\Psr\Log\LoggerInterface $logger) {
		$this->logger = $logger;
	}

	/**
	 * Load an image file into the object.
	 *
	 * @param string $filename The filename of the image.
	 * @param bool $loadexif Whether to load exif information (can cause memory problems with large files).
	 */
	protected function load_from_file($filename, $loadexif = true) {
		if (!file_exists($filename)) {
			throw new \Exception('File "'.$filename.'" not found', static::ERR_FILE_NOT_FOUND);
		}
		$this->filename = $filename;

		// Create image resource.
		$this->mime = \pdyn\filesystem\FilesystemUtils::get_mime_type($this->filename);
		switch ($this->mime) {
			case 'image/jpeg':
				$this->res = @imagecreatefromjpeg($this->filename);
				break;

			case 'image/png':
				$this->res = @imagecreatefrompng($this->filename);
				break;

			case 'image/gif':
				$this->res = @imagecreatefromgif($this->filename);
				break;

			default:
				throw new \Exception('Filetype not supported', static::ERR_FILETYPE_NOT_SUPPORTED);
		}
		if (empty($this->res)) {
			throw new \Exception('Error opening file.', static::ERR_BAD_INPUT);
		}

		if ($loadexif === true && $this->mime === 'image/jpeg') {
			$this->exif = $this->get_exif_pel();
		}
	}

	/**
	 * Load a different Image object into this Image copy. Note: Will copy the resource so changes to this will not affect the original.
	 *
	 * @param \pdyn\image\Image $image An Image object.
	 * @param bool $loadexif Whether to load exif information (can cause memory problems with large files).
	 */
	protected function load_from_object(Image $image, $loadexif = true) {
		$objectdata = $image->export();
		$this->filename = $objectdata['filename'];
		$this->mime = $objectdata['mime'];
		if ($loadexif === true) {
			$this->exif = $objectdata['exif'];
		}
		$this->res = static::clone_image_resource($objectdata['resource']);
	}

	/**
	 * Export class properties.
	 *
	 * @return array Array containing 'filename', 'mime', 'exif', and 'res'.
	 */
	public function export() {
		return [
			'filename' => $this->filename,
			'mime' => $this->mime,
			'exif' => $this->exif,
			'resource' => $this->res,
		];
	}

	/**
	 * Clone an image resource so changes to the resulting resource will not affect the original.
	 *
	 * @param resource $res The resource to clone.
	 * @return resource A cloned resource.
	 */
	public static function clone_image_resource($res) {
		$x = imagesx($res);
		$y = imagesy($res);
		$copy = imagecreatetruecolor($x, $y);
		imagecopy($copy, $res, 0, 0, 0, 0, $x, $y);
		return $copy;
	}

	/**
	 * Get image dimensions.
	 *
	 * @return array Array of 'w', and 'h' keys for the width and height of the image, respectively.
	 */
	public function get_dimensions() {
		return [
			'w' => imagesx($this->res),
			'h' => imagesy($this->res),
		];
	}

	/**
	 * Get information on the loaded file.
	 *
	 * @return array Array of file information.
	 */
	public function get_file_info() {
		return [
			'file' => $this->filename,
			'dir' => dirname($this->filename),
			'basename' => basename($this->filename)
		];
	}

	/**
	 * Get information from the image.
	 *
	 * @return array An array of image information.
	 */
	public function get_info() {
		$return = [
			'orientation' => 1,
			'timestamp' => time(),
			'filename' => basename($this->filename),
		];

		$exif = $this->get_exif();
		$return = array_merge($return, $exif);

		return $return;
	}

	/**
	 * Get image EXIF information.
	 *
	 * @return array Array of EXIF data.
	 */
	public function get_exif() {
		$return = [];
		set_error_handler(function() {});
		try {
			if (extension_loaded('exif') && $this->mime === 'image/jpeg') {
				$exif = exif_read_data($this->filename);
				$return = array_merge($exif, $return);
				if (isset($exif['Orientation']) && in_array($exif['Orientation'], [1, 2, 3, 4, 5, 6, 7, 8])) {
					$return['orientation'] = (int)$exif['Orientation'];
				}

				if (isset($exif['FileDateTime']) && \pdyn\datatype\Validator::timestamp($exif['FileDateTime']) === true) {
					$return['timestamp'] = (int)$exif['FileDateTime'];
				}

				if (!empty($exif['FileName']) && \pdyn\datatype\Validator::filename($exif['FileName']) === true) {
					$return['filename'] = (string)$exif['FileName'];
				}
			}
		} catch (\Exception $e) {
			$return = [];
		}
		restore_error_handler();

		return $return;
	}

	/**
	 * Get image EXIF information using the PEL library.
	 *
	 * @return array Array of EXIF data.
	 */
	public function get_exif_pel() {
		try {
			$peljpeg = new \lsolesen\pel\PelJpeg($this->filename);
			return $peljpeg->getExif();
		} catch (\Exception $e) {
			if (!empty($this->logger)) {
				$this->logger->debug('Problem getting exif information for file '.$this->filename.' Reason: '.$e->getMessage());
			}
			return [];
		}
	}

	/**
	 * Create a square thumbnail, for use in avatars/profile photos.
	 *
	 * @param int $size Size in pixels. Used for both with height and width.
	 */
	public function avatar($size) {
		// Fix orientation.
		$imageinfo = $this->get_info();
		if ($imageinfo['orientation'] !== 1) {
			$this->transform_for_exif_orientation($imageinfo['orientation']);
		}

		// Crop to square.
		$cropx = 0;
		$cropy = 0;
		$dimensions = $this->get_dimensions();
		$boxsize = min($dimensions['w'], $dimensions['h']);
		if ($boxsize === $dimensions['w']) {
			// Vertical image.
			$cropy = ($dimensions['h'] - $boxsize) / 2;
		} else {
			$cropx = ($dimensions['w'] - $boxsize) / 2;
		}
		$this->crop($cropx, $cropy, $boxsize, $boxsize);

		// Resize to desired size.
		$this->bounded_resize($size, $size);
	}

	/**
	 * Create a thumbnail for the image based on a maximum x and maximum y value.
	 *
	 * @param int $maxx Maximum x dimension.
	 * @param int $maxy Maximum y dimension.
	 * @return bool Success/Failure.
	 */
	public function thumbnail($maxx, $maxy) {

		if ($maxx !== null && \pdyn\datatype\Validator::intlike($maxx) !== true) {
			throw new \Exception('Invalid maxx parameter in \pdyn\image\Image', static::ERR_BAD_INPUT);
		}
		if ($maxy !== null && \pdyn\datatype\Validator::intlike($maxy) !== true) {
			throw new \Exception('Invalid maxy parameter in \pdyn\image\Image', static::ERR_BAD_INPUT);
		}

		// Fix orientation.
		$imageinfo = $this->get_info();
		if ($imageinfo['orientation'] !== 1) {
			$this->transform_for_exif_orientation($imageinfo['orientation']);
		}

		$this->bounded_resize((int)$maxx, (int)$maxy);
		return true;
	}

	/**
	 * Orients an image correctly based on an EXIF orientation flag.
	 *
	 * @param int $orientation An EXIF orientation flag. An INT from 1 to 8.
	 * @return bool Success/Failure.
	 */
	public function transform_for_exif_orientation($orientation) {
		$orientation = (int)$orientation;
		if (!in_array($orientation, [1, 2, 3, 4, 5, 6, 7, 8], true)) {
			throw new \Exception('Orientation must be an integer from 1 to 8', static::ERR_BAD_INPUT);
		}

		$orientation_fix = [
			1 => [],
			2 => ['flip_horiz'],
			3 => ['flip_both'],
			4 => ['flip_vert'],
			5 => ['rotate_cw', 'flip_horiz'],
			6 => ['rotate_cw'],
			7 => ['flip_horiz', 'rotate_cw'],
			8 => ['rotate_ccw'],
		];

		if (!empty($orientation_fix[$orientation])) {
			foreach ($orientation_fix[$orientation] as $op) {
				switch ($op) {
					case 'flip_horiz':
						$this->flip(static::IMG_FLIP_HORIZONTAL);
						break;
					case 'flip_vert':
						$this->flip(static::IMG_FLIP_VERTICAL);
						break;
					case 'flip_both':
						$this->flip(static::IMG_FLIP_BOTH);
						break;
					case 'rotate_cw':
						$this->rotate(static::ROTATE_CW);
						break;
					case 'rotate_ccw':
						$this->rotate(static::ROTATE_CCW);
						break;
				}
			}
		}

		// Reset the orientation flag.
		if (!empty($this->exif)) {
			$ifd0 = $this->exif->getTiff()->getIfd();
			$orient = $ifd0->getEntry(\lsolesen\pel\PelTag::ORIENTATION);
			if ($orient === null) {
				$orient = new \lsolesen\pel\PelEntryAscii(\lsolesen\pel\PelTag::ORIENTATION, 1);
				$ifd0->addEntry($orient);
			} else {
				$orient->setValue(1);
			}
		}

		return true;
	}

	/**
	 * Rotate the image.
	 *
	 * @param int $degrees Number of degrees to rotate image.
	 * @return bool Success/Failure
	 */
	public function rotate($degrees) {
		// Validate inputs.
		if (!is_int($degrees)) {
			return false;
		}

		// Rotate image.
		$this->res = imagerotate($this->res, $degrees, 0);

		return true;
	}

	/**
	 * Flip the image.
	 *
	 * @param ing $dir One of the IMG_FLIP_* constants. - IMG_FLIP_HORIZONTAL, IMG_FLIP_VERTICAL, or IMG_FLIP_BOTH
	 * @return bool Success/Failure
	 */
	public function flip($dir) {
		if (function_exists('imageflip')) {
			switch ($dir) {
				case static::IMG_FLIP_HORIZONTAL:
					$dir = \IMG_FLIP_HORIZONTAL;
					break;

				case static::IMG_FLIP_VERTICAL:
					$dir = \IMG_FLIP_VERTICAL;
					break;

				case static::IMG_FLIP_BOTH:
					$dir = \IMG_FLIP_BOTH;
					break;

				default:
					throw new \Exception('Please use one of the IMG_FLIP_* constants.', static::ERR_BAD_INPUT);
			}

			return imageflip($this->res, $dir);

		} else {

			$width = imagesx($this->res);
			$height = imagesy($this->res);

			$src_x = 0;
			$src_y = 0;
			$src_width = $width;
			$src_height = $height;

			switch ($dir) {
				case static::IMG_FLIP_VERTICAL:
					$src_y = $height - 1;
					$src_height = -$height;
				break;

				case static::IMG_FLIP_HORIZONTAL:
					$src_x = $width - 1;
					$src_width = -$width;
				break;

				case static::IMG_FLIP_BOTH:
					$src_x  = $width - 1;
					$src_y = $height - 1;
					$src_width = -$width;
					$src_height = -$height;
				break;

				default:
					return false;

			}

			$imgdest = imagecreatetruecolor($width, $height);
			$result = imagecopyresampled($imgdest, $this->res, 0, 0, $src_x, $src_y, $width, $height, $src_width, $src_height);
			if ($result === true) {
				$this->res = $imgdest;
			}
			return $result;
		}
	}

	/**
	 * Expands the image.
	 *
	 * @param  [type] $newx [description]
	 * @param  [type] $newy [description]
	 * @return [type]       [description]
	 */
	public function expand_canvas($newwidth, $newheight, $fillcolor = 'transparent') {
		$oldwidth = imagesx($this->res);
		$oldheight = imagesy($this->res);
		$newimage = imagecreatetruecolor($newwidth, $newheight);

		if ($fillcolor === 'transparent') {
			imagealphablending($newimage, false);
			imagesavealpha($newimage, true);
			imagefilledrectangle($newimage, 0, 0, $newwidth, $newwidth, IMG_COLOR_TRANSPARENT);
		} elseif ($fillcolor === 'transblack') {
			$bgcolor = imagecolorallocatealpha($newimage, 0, 0, 0, 80);
			imagealphablending($newimage, false);
			imagesavealpha($newimage, true);
			imagefilledrectangle($newimage, 0, 0, $newwidth, $newwidth, $bgcolor);
		}

		$dstx = ($newwidth > $oldwidth) ? ($newwidth - $oldwidth) / 2 : 0;
		$dsty = ($newheight > $oldheight) ? ($newheight - $oldheight) / 2 : 0;

		imagecopy($newimage, $this->res, $dstx, $dsty, 0, 0, $oldwidth, $oldheight);
		imagedestroy($this->res);
		$this->res = $newimage;
		return true;
	}

	/**
	 * Crop the image.
	 *
	 * @param int $x The x value of the top left corner of the crop box.
	 * @param int $y The y value of the top left corner of the crop box.
	 * @param int $width The width of the crop box.
	 * @param int $height The height of the crop box.
	 * @return bool Success/Failure.
	 */
	public function crop($x, $y, $width, $height) {
		$newimage = imagecreatetruecolor($width, $height);
		if ($this->mime === 'image/png') {
			imagealphablending($newimage, false);
			imagesavealpha($newimage, true);
			$transparent = imagecolorallocatealpha($newimage, 255, 255, 255, 127);
			imagefilledrectangle($newimage, 0, 0, $width, $height, $transparent);
		} else {
			$white = imagecolorallocate($newimage, 255, 255, 255);
			imagefilledrectangle($newimage, 0, 0, $width, $height, $white);
		}
		imagecopyresampled($newimage, $this->res, 0, 0, $x, $y, $width, $height, $width, $height);
		imagedestroy($this->res);
		$this->res = $newimage;
		return true;
	}

	/**
	 * Resizes the image into a bounding box while maintaining aspect ratio. Uses include creating thumbnails.
	 *
	 * @param int $maxx The maximum width (X dimension) the resulting image should have, in pixels. 0 = Unlimited
	 * @param int $maxy The maximum height (Y dimension) the resulting image should have, in pixels. 0 = Unlimited
	 * @return bool Success/Failure.
	 */
	public function bounded_resize($maxx, $maxy) {
		if (!is_int($maxx) || !is_int($maxy) || $maxx < 0 || $maxy < 0) {
			throw new \Exception('Both maxx and maxy must be non-negative integers', static::ERR_BAD_INPUT);
		}

		$width = imagesx($this->res);
		$height = imagesy($this->res);

		// Calculate thumbnail dimensions.
		if ($maxx === 0 && $maxy === 0) {
			// If both maxx and maxy are 0 then we're just converting to jpeg.
			$newheight = $height;
			$newwidth = $width;
		} else {
			$aspect = $width / $height;
			if ($maxx === 0) {
				// Unlimited x.
				$newheight = $maxy;
				$newwidth = $maxy * $aspect;
			} elseif ($maxy === 0) {
				// Unlimited y.
				$newwidth = $maxx;
				$newheight = $maxx / $aspect;
			} else {
				if ($aspect < 1) {
					// Vertical image.
					$newheight = $maxy;
					$newwidth = $newheight * $aspect;
					if ($newwidth > $maxx) {
						$newwidth = $maxx;
						$newheight = $maxx / $aspect;
					}
				} elseif ($aspect > 1) {
					// Horizontal image.
					$newwidth = $maxx;
					$newheight = $maxx / $aspect;
					if ($newheight > $maxy) {
						$newheight = $maxy;
						$newwidth = $maxy * $aspect;
					}
				} else {
					// Square image.
					$dim = min($maxx, $maxy);
					$newwidth = $dim;
					$newheight = $dim;
				}
			}
		}

		$newimage = imagecreatetruecolor($newwidth, $newheight);

		if ($this->mime === 'image/png') {
			imagealphablending($newimage, false);
			imagesavealpha($newimage, true);
			$transparent = imagecolorallocatealpha($newimage, 255, 255, 255, 127);
			imagefilledrectangle($newimage, 0, 0, $newwidth, $newheight, $transparent);
		} else {
			$white = imagecolorallocate($newimage, 255, 255, 255);
			imagefilledrectangle($newimage, 0, 0, $newwidth, $newheight, $white);
		}

		imagecopyresampled($newimage, $this->res, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
		imagedestroy($this->res);
		$this->res = $newimage;
		return true;
	}

	/**
	 * Change the file format of the image.
	 *
	 * @param string $format The new format. Can be 'jpg', 'gif', or 'png'.
	 */
	public function convert_format_to($format) {
		switch ($format) {
			case 'jpg':
				$this->mime = 'image/jpeg';
				break;
			case 'png':
				$this->mime = 'image/png';
				break;
			case 'gif':
				$this->mime = 'image/gif';
				break;
			default:
				throw new \Exception('Specified format not supported', static::ERR_FILETYPE_NOT_SUPPORTED);
		}
	}

	/**
	 * Save the image.
	 *
	 * @return bool Success/Failure.
	 */
	public function save($filename = '', $appendext = false) {
		if (is_dir($filename)) {
			$fileinfo = $this->get_file_info();
			$filename .= '/'.$fileinfo['basename'];
		} else {
			$filename = (!empty($filename)) ? $filename : $this->filename;
		}
		if ($appendext === true) {
			$ext = $this->get_extension();
			if (!empty($ext)) {
				$filename .= '.'.$ext;
			}
		}
		return $this->output($filename);
	}

	/**
	 * Get the relevant file extension for the currently loaded image.
	 *
	 * @return string The file extension for the currently loaded image, based on the image's mime type.
	 */
	public function get_extension() {
		switch ($this->mime) {
			case 'image/jpeg':
				return 'jpg';

			case 'image/png':
				return 'png';

			case 'image/gif':
				return 'gif';
		}
		return false;
	}

	/**
	 * Display the current image.
	 *
	 * @return bool Success/Failure.
	 */
	public function display() {
		header('Content-Type: '.$this->mime.';charset=utf-8');
		return $this->output(null);
	}

	/**
	 * Output the current image.
	 *
	 * @param string|null $to Where to output the image. Null means output directly (i.e. to the browser)
	 * @return bool Success/Failure
	 */
	protected function output($to = null) {
		switch ($this->mime) {
			case 'image/jpeg':
				imagejpeg($this->res, $to, 50);
				if (!empty($this->exif) && !empty($to)) {
					$peljpeg = new \lsolesen\pel\PelJpeg($to);
					$peljpeg->setExif($this->exif);
					$peljpeg->saveFile($to);
				}
				return true;

			case 'image/png':
				imagepng($this->res, $to, 9);
				return true;

			case 'image/gif':
				imagegif($this->res, $to);
				return true;

			default:
				return false;
		}
	}

	/**
	 * Free resource memory on destruct.
	 */
	public function __destruct() {
		imagedestroy($this->res);
	}
}
