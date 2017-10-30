/*
 * Copyright (c) 2015
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */
describe('OCA.Versions.VersionsTabView', function() {
	var VersionCollection = OCA.Versions.VersionCollection;
	var VersionModel = OCA.Versions.VersionModel;
	var VersionsTabView = OCA.Versions.VersionsTabView;

	var fetchStub, fileInfoModel, tabView, testVersions, clock;

	beforeEach(function() {
		clock = sinon.useFakeTimers(Date.UTC(2015, 6, 17, 1, 2, 0, 3));
		var time1 = Date.UTC(2015, 6, 17, 1, 2, 0, 3) / 1000;
		var time2 = Date.UTC(2015, 6, 15, 1, 2, 0, 3) / 1000;

		var version1 = new VersionModel({
			id: time1,
			timestamp: time1,
			name: 'some file.txt',
			size: 140,
			fullPath: '/subdir/some file.txt'
		});
		var version2 = new VersionModel({
			id: time2,
			timestamp: time2,
			name: 'some file.txt',
			size: 150,
			fullPath: '/subdir/some file.txt'
		});

		testVersions = [version1, version2];

		fetchStub = sinon.stub(VersionCollection.prototype, 'fetch');
		fileInfoModel = new OCA.Files.FileInfoModel({
			id: 123,
			name: 'test.txt',
			permissions: OC.PERMISSION_READ | OC.PERMISSION_UPDATE
		});
		tabView = new VersionsTabView();
		tabView.render();
	});

	afterEach(function() {
		fetchStub.restore();
		tabView.remove();
		clock.restore();
	});

	describe('rendering', function() {
		it('reloads matching versions when setting file info model', function() {
			tabView.setFileInfo(fileInfoModel);
			expect(fetchStub.calledOnce).toEqual(true);
		});

		it('renders loading icon while fetching versions', function() {
			tabView.setFileInfo(fileInfoModel);
			tabView.collection.trigger('request');

			expect(tabView.$el.find('.loading').length).toEqual(1);
			expect(tabView.$el.find('.versions li').length).toEqual(0);
		});

		it('renders versions', function() {

			tabView.setFileInfo(fileInfoModel);
			tabView.collection.set(testVersions);

			var version1 = testVersions[0];
			var version2 = testVersions[1];
			var $versions = tabView.$el.find('.versions>li');
			expect($versions.length).toEqual(2);
			var $item = $versions.eq(0);
			expect($item.find('.downloadVersion').attr('href')).toEqual(version1.getDownloadUrl());
			expect($item.find('.versiondate').text()).toEqual('seconds ago');
			expect($item.find('.size').text()).toEqual('< 1 KB');
			expect($item.find('.revertVersion').length).toEqual(1);
			expect($item.find('.preview').attr('src')).toEqual(version1.getPreviewUrl());

			$item = $versions.eq(1);
			expect($item.find('.downloadVersion').attr('href')).toEqual(version2.getDownloadUrl());
			expect($item.find('.versiondate').text()).toEqual('2 days ago');
			expect($item.find('.size').text()).toEqual('< 1 KB');
			expect($item.find('.revertVersion').length).toEqual(1);
			expect($item.find('.preview').attr('src')).toEqual(version2.getPreviewUrl());
		});

		it('does not render revert button when no update permissions', function() {

			fileInfoModel.set('permissions', OC.PERMISSION_READ);
			tabView.setFileInfo(fileInfoModel);
			tabView.collection.set(testVersions);

			var version1 = testVersions[0];
			var version2 = testVersions[1];
			var $versions = tabView.$el.find('.versions>li');
			expect($versions.length).toEqual(2);
			var $item = $versions.eq(0);
			expect($item.find('.downloadVersion').attr('href')).toEqual(version1.getDownloadUrl());
			expect($item.find('.versiondate').text()).toEqual('seconds ago');
			expect($item.find('.revertVersion').length).toEqual(0);
			expect($item.find('.preview').attr('src')).toEqual(version1.getPreviewUrl());

			$item = $versions.eq(1);
			expect($item.find('.downloadVersion').attr('href')).toEqual(version2.getDownloadUrl());
			expect($item.find('.versiondate').text()).toEqual('2 days ago');
			expect($item.find('.revertVersion').length).toEqual(0);
			expect($item.find('.preview').attr('src')).toEqual(version2.getPreviewUrl());
		});
	});
});
