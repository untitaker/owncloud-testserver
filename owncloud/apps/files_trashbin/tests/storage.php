<?php
/**
 * Copyright (c) 2015 Vincent Petry <pvince81@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Files_trashbin\Tests\Storage;

use OC\Files\Storage\Home;
use OC\Files\Storage\Temporary;
use OC\Files\Mount\MountPoint;
use OC\Files\Filesystem;

class Storage extends \Test\TestCase {
	/**
	 * @var string
	 */
	private $user;

	/**
	 * @var \OC\Files\Storage\Storage
	 **/
	private $originalStorage;

	/**
	 * @var \OC\Files\View
	 */
	private $rootView;

	/**
	 * @var \OC\Files\View
	 */
	private $userView;

	protected function setUp() {
		parent::setUp();

		\OC_Hook::clear();
		\OCA\Files_Trashbin\Trashbin::registerHooks();

		$this->user = $this->getUniqueId('user');
		\OC::$server->getUserManager()->createUser($this->user, $this->user);

		// this will setup the FS
		$this->loginAsUser($this->user);

		$this->originalStorage = \OC\Files\Filesystem::getStorage('/');

		\OCA\Files_Trashbin\Storage::setupStorage();

		$this->rootView = new \OC\Files\View('/');
		$this->userView = new \OC\Files\View('/' . $this->user . '/files/');
		$this->userView->file_put_contents('test.txt', 'foo');

		$this->userView->mkdir('folder');
		$this->userView->file_put_contents('folder/inside.txt', 'bar');
	}

	protected function tearDown() {
		\OC\Files\Filesystem::getLoader()->removeStorageWrapper('oc_trashbin');
		\OC\Files\Filesystem::mount($this->originalStorage, array(), '/');
		$this->logout();
		\OC_User::deleteUser($this->user);
		\OC_Hook::clear();
		parent::tearDown();
	}

	/**
	 * Test that deleting a file puts it into the trashbin.
	 */
	public function testSingleStorageDeleteFile() {
		$this->assertTrue($this->userView->file_exists('test.txt'));
		$this->userView->unlink('test.txt');
		list($storage,) = $this->userView->resolvePath('test.txt');
		$storage->getScanner()->scan(''); // make sure we check the storage
		$this->assertFalse($this->userView->getFileInfo('test.txt'));

		// check if file is in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files/');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('test.txt', substr($name, 0, strrpos($name, '.')));
	}

	/**
	 * Test that deleting a folder puts it into the trashbin.
	 */
	public function testSingleStorageDeleteFolder() {
		$this->assertTrue($this->userView->file_exists('folder/inside.txt'));
		$this->userView->rmdir('folder');
		list($storage,) = $this->userView->resolvePath('folder/inside.txt');
		$storage->getScanner()->scan(''); // make sure we check the storage
		$this->assertFalse($this->userView->getFileInfo('folder'));

		// check if folder is in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files/');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('folder', substr($name, 0, strrpos($name, '.')));

		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files/' . $name . '/');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('inside.txt', $name);
	}

	/**
	 * Test that deleting a file from another mounted storage properly
	 * lands in the trashbin. This is a cross-storage situation because
	 * the trashbin folder is in the root storage while the mounted one
	 * isn't.
	 */
	public function testCrossStorageDeleteFile() {
		$storage2 = new Temporary(array());
		\OC\Files\Filesystem::mount($storage2, array(), $this->user . '/files/substorage');

		$this->userView->file_put_contents('substorage/subfile.txt', 'foo');
		$storage2->getScanner()->scan('');
		$this->assertTrue($storage2->file_exists('subfile.txt'));
		$this->userView->unlink('substorage/subfile.txt');

		$storage2->getScanner()->scan('');
		$this->assertFalse($this->userView->getFileInfo('substorage/subfile.txt'));
		$this->assertFalse($storage2->file_exists('subfile.txt'));

		// check if file is in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('subfile.txt', substr($name, 0, strrpos($name, '.')));
	}

	/**
	 * Test that deleting a folder from another mounted storage properly
	 * lands in the trashbin. This is a cross-storage situation because
	 * the trashbin folder is in the root storage while the mounted one
	 * isn't.
	 */
	public function testCrossStorageDeleteFolder() {
		$storage2 = new Temporary(array());
		\OC\Files\Filesystem::mount($storage2, array(), $this->user . '/files/substorage');

		$this->userView->mkdir('substorage/folder');
		$this->userView->file_put_contents('substorage/folder/subfile.txt', 'bar');
		$storage2->getScanner()->scan('');
		$this->assertTrue($storage2->file_exists('folder/subfile.txt'));
		$this->userView->rmdir('substorage/folder');

		$storage2->getScanner()->scan('');
		$this->assertFalse($this->userView->getFileInfo('substorage/folder'));
		$this->assertFalse($storage2->file_exists('folder/subfile.txt'));

		// check if folder is in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('folder', substr($name, 0, strrpos($name, '.')));

		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files/' . $name . '/');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('subfile.txt', $name);
	}

	/**
	 * Test that deleted versions properly land in the trashbin.
	 */
	public function testDeleteVersionsOfFile() {
		\OCA\Files_Versions\Hooks::connectHooks();

		// trigger a version (multiple would not work because of the expire logic)
		$this->userView->file_put_contents('test.txt', 'v1');

		$results = $this->rootView->getDirectoryContent($this->user . '/files_versions/');
		$this->assertEquals(1, count($results));

		$this->userView->unlink('test.txt');

		// rescan trash storage
		list($rootStorage,) = $this->rootView->resolvePath($this->user . '/files_trashbin');
		$rootStorage->getScanner()->scan('');

		// check if versions are in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/versions');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('test.txt.v', substr($name, 0, strlen('test.txt.v')));
	}

	/**
	 * Test that deleted versions properly land in the trashbin.
	 */
	public function testDeleteVersionsOfFolder() {
		\OCA\Files_Versions\Hooks::connectHooks();

		// trigger a version (multiple would not work because of the expire logic)
		$this->userView->file_put_contents('folder/inside.txt', 'v1');

		$results = $this->rootView->getDirectoryContent($this->user . '/files_versions/folder/');
		$this->assertEquals(1, count($results));

		$this->userView->rmdir('folder');

		// rescan trash storage
		list($rootStorage,) = $this->rootView->resolvePath($this->user . '/files_trashbin');
		$rootStorage->getScanner()->scan('');

		// check if versions are in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/versions');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('folder.d', substr($name, 0, strlen('folder.d')));

		// check if versions are in trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/versions/' . $name . '/');
		$this->assertEquals(1, count($results));
		$name = $results[0]->getName();
		$this->assertEquals('inside.txt.v', substr($name, 0, strlen('inside.txt.v')));
	}

	/**
	 * Test that versions are not auto-trashed when moving a file between
	 * storages. This is because rename() between storages would call
	 * unlink() which should NOT trigger the version deletion logic.
	 */
	public function testKeepFileAndVersionsWhenMovingFileBetweenStorages() {
		\OCA\Files_Versions\Hooks::connectHooks();

		$storage2 = new Temporary(array());
		\OC\Files\Filesystem::mount($storage2, array(), $this->user . '/files/substorage');

		// trigger a version (multiple would not work because of the expire logic)
		$this->userView->file_put_contents('test.txt', 'v1');

		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files');
		$this->assertEquals(0, count($results));

		$results = $this->rootView->getDirectoryContent($this->user . '/files_versions/');
		$this->assertEquals(1, count($results));

		// move to another storage
		$this->userView->rename('test.txt', 'substorage/test.txt');
		$this->assertTrue($this->userView->file_exists('substorage/test.txt'));

		// rescan trash storage
		list($rootStorage,) = $this->rootView->resolvePath($this->user . '/files_trashbin');
		$rootStorage->getScanner()->scan('');

		// versions were moved too
		$results = $this->rootView->getDirectoryContent($this->user . '/files_versions/substorage');
		$this->assertEquals(1, count($results));

		// check that nothing got trashed by the rename's unlink() call
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files');
		$this->assertEquals(0, count($results));

		// check that versions were moved and not trashed
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/versions/');
		$this->assertEquals(0, count($results));
	}

	/**
	 * Test that versions are not auto-trashed when moving a file between
	 * storages. This is because rename() between storages would call
	 * unlink() which should NOT trigger the version deletion logic.
	 */
	public function testKeepFileAndVersionsWhenMovingFolderBetweenStorages() {
		\OCA\Files_Versions\Hooks::connectHooks();

		$storage2 = new Temporary(array());
		\OC\Files\Filesystem::mount($storage2, array(), $this->user . '/files/substorage');

		// trigger a version (multiple would not work because of the expire logic)
		$this->userView->file_put_contents('folder/inside.txt', 'v1');

		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files');
		$this->assertEquals(0, count($results));

		$results = $this->rootView->getDirectoryContent($this->user . '/files_versions/folder/');
		$this->assertEquals(1, count($results));

		// move to another storage
		$this->userView->rename('folder', 'substorage/folder');
		$this->assertTrue($this->userView->file_exists('substorage/folder/inside.txt'));

		// rescan trash storage
		list($rootStorage,) = $this->rootView->resolvePath($this->user . '/files_trashbin');
		$rootStorage->getScanner()->scan('');

		// versions were moved too
		$results = $this->rootView->getDirectoryContent($this->user . '/files_versions/substorage/folder/');
		$this->assertEquals(1, count($results));

		// check that nothing got trashed by the rename's unlink() call
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files');
		$this->assertEquals(0, count($results));

		// check that versions were moved and not trashed
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/versions/');
		$this->assertEquals(0, count($results));
	}

	/**
	 * Delete should fail is the source file cant be deleted
	 */
	public function testSingleStorageDeleteFileFail() {
		/**
		 * @var \OC\Files\Storage\Temporary | \PHPUnit_Framework_MockObject_MockObject $storage
		 */
		$storage = $this->getMockBuilder('\OC\Files\Storage\Temporary')
			->setConstructorArgs([[]])
			->setMethods(['rename', 'unlink'])
			->getMock();

		$storage->expects($this->any())
			->method('unlink')
			->will($this->returnValue(false));

		$cache = $storage->getCache();

		Filesystem::mount($storage, [], '/' . $this->user . '/files');
		$this->userView->file_put_contents('test.txt', 'foo');
		$this->assertTrue($storage->file_exists('test.txt'));
		$this->assertFalse($this->userView->unlink('test.txt'));
		$this->assertTrue($storage->file_exists('test.txt'));
		$this->assertTrue($cache->inCache('test.txt'));

		// file should not be in the trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files/');
		$this->assertEquals(0, count($results));
	}

	/**
	 * Delete should fail is the source folder cant be deleted
	 */
	public function testSingleStorageDeleteFolderFail() {
		/**
		 * @var \OC\Files\Storage\Temporary | \PHPUnit_Framework_MockObject_MockObject $storage
		 */
		$storage = $this->getMockBuilder('\OC\Files\Storage\Temporary')
			->setConstructorArgs([[]])
			->setMethods(['rename', 'unlink', 'rmdir'])
			->getMock();

		$storage->expects($this->any())
			->method('rmdir')
			->will($this->returnValue(false));

		$cache = $storage->getCache();

		Filesystem::mount($storage, [], '/' . $this->user . '/files');
		$this->userView->mkdir('folder');
		$this->userView->file_put_contents('folder/test.txt', 'foo');
		$this->assertTrue($storage->file_exists('folder/test.txt'));
		$this->assertFalse($this->userView->rmdir('folder'));
		$this->assertTrue($storage->file_exists('folder'));
		$this->assertTrue($storage->file_exists('folder/test.txt'));
		$this->assertTrue($cache->inCache('folder'));
		$this->assertTrue($cache->inCache('folder/test.txt'));

		// file should not be in the trashbin
		$results = $this->rootView->getDirectoryContent($this->user . '/files_trashbin/files/');
		$this->assertEquals(0, count($results));
	}
}
