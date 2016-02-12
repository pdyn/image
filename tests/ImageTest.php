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

namespace pdyn\image\tests;

/**
 * Test image library.
 *
 * @group pdyn
 * @group pdyn_image
 * @codeCoverageIgnore
 */
class ImageTest extends \PHPUnit_Framework_TestCase {

	/**
	 * Dataprovider for test_get_info.
	 *
	 * @return array Array of arrays of test parameters.
	 */
	public function dataprovider_get_info() {
		return [
			[
				'Orientation1.jpg',
				['orientation' => 1, 'FileName' => 'Orientation1.jpg'],
			],
			[
				'Orientation2.jpg',
				['orientation' => 2, 'FileName' => 'Orientation2.jpg'],
			],
		];
	}

	/**
	 * Test get_info function.
	 *
	 * @dataProvider dataprovider_get_info
	 * @param string $file The filename of the photo to use. Should be a bare filename, no path, to a photo in the fixtures folder.
	 * @param array $expected The expected function return.
	 */
	public function test_get_info($file, $expected) {
		$img = new \pdyn\image\Image(__DIR__.'/fixtures/'.$file);
		$actual = $img->get_info();
		foreach ($expected as $key => $val) {
			$this->assertArrayHasKey($key, $actual);
			$this->assertEquals($val, $actual[$key]);
		}
	}
}
