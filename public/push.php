<?php
require_once(__DIR__ . '/../inc/core.php');

require_auth();

if (count($_FILES)==0){
	api_error('400', 'No package file');
}
$upload_filename = "";

foreach ($_FILES as $value)
{
        $upload_filename=$value['tmp_name'];
        break;
}

// Try to find NuSpec file
$package_zip = new ZipArchive();
if($package_zip->open($upload_filename)===true){
	$nuspec_index = false;
	for ($i = 0; $i < $package_zip->numFiles; $i++) {
		if (substr($package_zip->getNameIndex($i), -7) === '.nuspec') {
			$nuspec_index = $i;
			break;
		}
	}
	if ($nuspec_index === false) {
		api_error('400', 'NuSpec file not found in package');
	}
	$nuspec_string = $package_zip->getFromIndex($nuspec_index);
}
else{
	//For some reason, a nupkg made by recent nuget.exe cannot be sometimes opened by ZipArchive.
	$zipdir_path = '/tmp/unzip';
	
	shell_exec("unzip $upload_filename *.nuspec -d $zipdir_path");
	
	$files = glob($zipdir_path.'/*.nuspec');
	if(empty($files)){
		shell_exec("rm -rf $zipdir_path");
		api_error('400', 'NuSpec file not found in package');
	}
	
	$nuspec_path = $files[0];
	$nuspec_string = file_get_contents($nuspec_path);

	shell_exec("rm -rf $zipdir_path");
}

$nuspec = simplexml_load_string($nuspec_string);

if (!$nuspec->metadata->id || !$nuspec->metadata->version) {
	api_error('400', 'ID or version is missing');
}

$id = (string)$nuspec->metadata->id;
$version = (string)$nuspec->metadata->version;
$valid_id = '/^[A-Z0-9\.\~\+\_\-]+$/i';
if (!preg_match($valid_id, $id) || !preg_match($valid_id, $version)) {
	api_error('400', 'Invalid ID or version');
}

if (DB::validateIdAndVersion($id, $version)) {
	api_error('409', 'This package version already exists');
}

$hash = base64_encode(hash_file('sha512', $upload_filename, true));
$filesize = filesize($upload_filename);
$dependencies = [];


if ($nuspec->metadata->dependencies) {
	if ($nuspec->metadata->dependencies->dependency) {
		// Dependencies that are not specific to any framework
		foreach ($nuspec->metadata->dependencies->dependency as $dependency) {
			$dependencies[] = [
				'framework' => null,
				'id' => (string)$dependency['id'],
				'version' => (string)$dependency['version']
			];
		}
	}

	if ($nuspec->metadata->dependencies->group) {
		// Dependencies that are specific to a particular framework
		foreach ($nuspec->metadata->dependencies->group as $group) {
			foreach ($group->dependency as $dependency) {
				$dependencies[] = [
					'framework' => (string)$group['targetFramework'],
					'id' => (string)$dependency['id'],
					'version' => (string)$dependency['version']
				];
			}
		}
	}
}

// Move package into place.
$dir = Config::$packageDir . $id . DIRECTORY_SEPARATOR;
$path = $dir . $version . '.nupkg';

if (!file_exists($dir)) {
	mkdir($dir, /* mode */ 0755, /* recursive */ true);
}
if (!move_uploaded_file($upload_filename, $path)) {
	api_error('500', 'Could not save file');
}

// Update database
DB::insertOrUpdatePackage([
	':id' => $id,
	':title' => $nuspec->metadata->title,
	':version' => $version
]);
DB::insertVersion([
	':Authors' => $nuspec->metadata->authors,
	':Copyright' => $nuspec->metadata->copyright,
	':Dependencies' => $dependencies,
	':Description' => $nuspec->metadata->description,
	':PackageHash' => $hash,
	':PackageHashAlgorithm' => 'SHA512',
	':PackageSize' => $filesize,
	':IconUrl' => $nuspec->metadata->iconUrl,
	':IsPrerelease' => strpos($version, '-') !== false,
	':LicenseUrl' => $nuspec->metadata->licenseUrl,
	':Owners' => $nuspec->metadata->owners,
	':PackageId' => $id,
	':ProjectUrl' => $nuspec->metadata->projectUrl,
	':ReleaseNotes' => $nuspec->metadata->releaseNotes,
	':RequireLicenseAcceptance' => $nuspec->metadata->requireLicenseAcceptance === 'true',
	':Tags' => $nuspec->metadata->tags,
	':Title' => $nuspec->metadata->title,
	':Version' => $version,
]);

// All done!
header('HTTP/1.1 201 Created');
