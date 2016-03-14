<?php
/**
 *	Filemanager PHP class
 *
 *	filemanager.class.php
 *	class for the filemanager.php connector
 *
 *	@license	MIT License
 *	@author		Riaan Los <mail (at) riaanlos (dot) nl>
 *	@author		Simon Georget <simon (at) linea21 (dot) com>
 *	@author		Pavel Solomienko <https://github.com/servocoder/>
 *	@copyright	Authors
 */

class Filemanager {

	protected $config = array();
	protected $language = array();
	protected $get = array();
	protected $post = array();
	protected $properties = array();
	protected $item = array();
	protected $languages = array();
	protected $allowed_actions = array();
	protected $root_path = ''; // Filemanager root folder
	protected $doc_root = '';		// root folder known by JS : $this->config['options']['fileRoot'] (filepath or '/') or $_SERVER['DOCUMENT_ROOT'] - overwritten by setFileRoot() method
	protected $dynamic_fileroot = ''; // Only set if setFileRoot() is called. Second part of the path : '/Filemanager/assets/' ( doc_root - $_SERVER['DOCUMENT_ROOT'])
	protected $path_to_files = ''; // path to FM userfiles folder - automatically computed by the PHP class, something like '/var/www/Filemanager/userfiles'
	protected $logger = false;
	protected $logfile = '';
	protected $cachefolder = '_thumbs/';
	protected $thumbnail_width = 64;
	protected $thumbnail_height = 64;
	protected $separator = 'userfiles'; // @todo fix keep it or not?
	protected $connector_script_url = 'connectors/php/filemanager.php';

	public function __construct($extraConfig = '')
    {
		$this->root_path = isset($extraConfig['root_path']) ? $extraConfig['root_path'] : dirname(dirname(dirname(__FILE__)));

		// getting default config file
		$content = file_get_contents($this->root_path . "/scripts/filemanager.config.default.json");
		$config_default = json_decode($content, true);

		// getting user config file
		if(isset($_REQUEST['config'])) {
			$this->getvar('config');
			if (file_exists($this->root_path . "/scripts/" . $_REQUEST['config'])) {
				$this->__log('Loading ' . basename($this->get['config']) . ' config file.');
				$content = file_get_contents($this->root_path . "/scripts/" . basename($this->get['config']));
			} else {
				$this->__log($this->get['config'] . ' config file does not exists.');
				$this->error("Given config file (".basename($this->get['config']).") does not exist !");
			}
		} else {
			$content = file_get_contents($this->root_path . "/scripts/filemanager.config.json");
		}
		$config = json_decode($content, true);

		// Prevent following bug https://github.com/simogeo/Filemanager/issues/398
		$config_default['security']['uploadRestrictions'] = array();

		if(!$config) {
			$this->error("Error parsing the settings file! Please check your JSON syntax.");
		}
		$this->config = array_replace_recursive ($config_default, $config);

		// override config options if needed
		if(!empty($extraConfig)) {
			$this->setup($extraConfig);
		}

        if($this->config['options']['fileConnector']) {
            $this->connector_script_url = $this->config['options']['fileConnector'];
        }

		// set logfile path according to system if not set into config file
		if(!isset($this->config['options']['logfile']))
			$this->config['options']['logfile'] = sys_get_temp_dir(). '/filemanager.log';

		$this->properties = array(
			'Date Created' => null,
			'Date Modified' => null,
			'Height' => null,
			'Width' => null,
			'Size' => null
		);

		// Log actions or not?
		if ($this->config['options']['logger'] == true ) {
			if(isset($this->config['options']['logfile'])) {
				$this->logfile = $this->config['options']['logfile'];
			}
			$this->enableLog();
		}

		// if fileRoot is set manually, $this->doc_root takes fileRoot value
		// for security check in is_valid_path() method
		// else it takes $_SERVER['DOCUMENT_ROOT'] default value
		if ($this->config['options']['fileRoot'] !== false ) {
			if($this->config['options']['serverRoot'] === true) {
				$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
				$this->separator = basename($this->config['options']['fileRoot']);
				$this->path_to_files = $_SERVER['DOCUMENT_ROOT'] . '/' . $this->config['options']['fileRoot'];
			} else {
				$this->doc_root = $this->config['options']['fileRoot'];
				$this->separator = basename($this->config['options']['fileRoot']);
				$this->path_to_files = $this->config['options']['fileRoot'];
			}
		} else {
			$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
			$this->path_to_files = $this->root_path . '/' . $this->separator . '/' ;
		}
		$this->cleanPath($this->path_to_files);

		$this->__log(__METHOD__ . ' $this->root_path value ' . $this->root_path);
		$this->__log(__METHOD__ . ' $this->path_to_files ' . $this->path_to_files);
		$this->__log(__METHOD__ . ' $this->doc_root value ' . $this->doc_root);
		$this->__log(__METHOD__ . ' $this->separator value ' . $this->separator);

		$this->setParams();
		$this->setPermissions();
		$this->availableLanguages();
		$this->loadLanguageFile();
	}

    /**
     * Extend config from file
     * @param array $extraconfig Should be formatted as json config array.
     */
	public function setup($extraconfig)
    {
		// Prevent following bug https://github.com/simogeo/Filemanager/issues/398
		$config_default['security']['uploadRestrictions'] = array();

		$this->config = array_replace_recursive($this->config, $extraconfig);
	}

    /**
     * Allow Filemanager to be used with dynamic folders
     * @param string $path - i.e '/var/www/'
     * @param bool $mkdir
     */
	public function setFileRoot($path, $mkdir = false)
    {
		// Paths are bit complex to handle - kind of nightmare actually ....
		// 3 parts are availables
		// [1] $this->doc_root. The first part of the path : '/var/www'
		// [2] $this->dynamic_fileroot. The second part of the path : '/Filemanager/assets/' ( doc_root - $_SERVER['DOCUMENT_ROOT'])
		// [3] $this->path_to_files or $this->doc_root. The full path : '/var/www/Filemanager/assets/'

		if($this->config['options']['serverRoot'] === true) {
			$this->doc_root = $_SERVER['DOCUMENT_ROOT'] . '/' . $path . '/';
		} else {
			$this->doc_root = $path . '/';
		}
		$this->cleanPath($this->doc_root);

		// necessary for retrieving path when set dynamically with $fm->setFileRoot() method
		// https://github.com/simogeo/Filemanager/issues/258 @todo to explore deeper
		$this->dynamic_fileroot = str_replace($_SERVER['DOCUMENT_ROOT'], '', $this->doc_root);
		$this->path_to_files = $this->doc_root;
		$this->separator = basename($this->doc_root);

		// do we create folder ?
		if($mkdir && !file_exists($this->doc_root)) {
			mkdir($this->doc_root, 0755, true);
			$this->__log(__METHOD__ . ' creating  ' . $this->doc_root. ' folder through mkdir()');
		}

		$this->__log(__METHOD__ . ' $this->doc_root value overwritten : ' . $this->doc_root);
		$this->__log(__METHOD__ . ' $this->dynamic_fileroot value ' . $this->dynamic_fileroot);
		$this->__log(__METHOD__ . ' $this->path_to_files ' . $this->path_to_files);
		$this->__log(__METHOD__ . ' $this->separator value ' . $this->separator);
	}

    /**
     * Echo error message and terminate the application
     * @param $string
     * @param bool $textarea
     */
	public function error($string, $textarea = false)
    {
		$array = array(
			'Error' => $string,
			'Code' => '-1',
			'Properties' => $this->properties
		);

		$this->__log( __METHOD__ . ' - error message : ' . $string);

		if($textarea) {
			echo '<textarea>' . json_encode($array) . '</textarea>';
		} else {
			echo json_encode($array);
		}
		die();
	}

	public function lang($string)
    {
		if(isset($this->language[$string]) && $this->language[$string]!='') {
			return $this->language[$string];
		} else {
			return 'Language string error on ' . $string;
		}
	}

    /**
     * Retrieve data from $_GET global var
     * @param string $var
     * @param bool $sanitize
     * @return bool
     */
	public function getvar($var, $sanitize = true)
    {
		if(!isset($_GET[$var]) || $_GET[$var]=='') {
			$this->error(sprintf($this->lang('INVALID_VAR'),$var));
		} else {
			if($sanitize) {
				$this->get[$var] = $this->sanitize($_GET[$var]);
			} else {
				$this->get[$var] = $_GET[$var];
			}
			return true;
		}
	}

    /**
     * Retrieve data from $_POST global var
     * @param string $var
     * @param bool $sanitize
     * @return bool
     */
	public function postvar($var, $sanitize = true)
    {
		if(!isset($_POST[$var]) || ($var != 'content' && $_POST[$var]=='')) {
			$this->error(sprintf($this->lang('INVALID_VAR'),$var));
		} else {
			if($sanitize) {
				$this->post[$var] = $this->sanitize($_POST[$var]);
			} else {
				$this->post[$var] = $_POST[$var];
			}
			return true;
		}
	}

    /**
     * Returns file info - filemanager action
     * @return array
     */
	public function getinfo()
    {
		// handle path when set dynamically with setFileRoot() method
		if($this->dynamic_fileroot != '') {
			$path = $this->dynamic_fileroot . $this->get['path'];
			// $path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $this->path_to_files) . $this->get['path']; // instruction could replace the line above
			$this->cleanPath($path);
		} else {
			$path = $this->get['path'];
		}

		$current_path = $this->getFullPath($path);
		$filename = basename($current_path);

		if(!$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		if(!file_exists($current_path)) {
			$this->error(sprintf($this->lang('FILE_DOES_NOT_EXIST'), $path));
		}

		// check if file is readable
		if(!$this->has_system_permission($current_path, array('r'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if file is allowed regarding the security Policy settings
		if(in_array($filename, $this->config['exclude']['unallowed_files']) || preg_match( $this->config['exclude']['unallowed_files_REGEXP'], $filename)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		$this->item = array();
		$this->item['properties'] = $this->properties;
		$this->get_file_info('', false);

		$array = array(
			'Path' => $this->formatPath($path),
			'Filename' => $this->item['filename'],
			'File Type' => $this->item['filetype'],
			'Protected' => $this->item['protected'],
			'Preview' => $this->item['preview'],
			'Properties' => $this->item['properties'],
			'Error' => "",
			'Code' => 0
		);
		return $array;
	}

    /**
     * Open specified folder - filemanager action
     * @return array
     */
	public function getfolder()
    {
		$array = array();
		$filesDir = array();

		$current_path = $this->getFullPath();

		if(!$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		if(!is_dir($current_path)) {
			$this->error(sprintf($this->lang('DIRECTORY_NOT_EXIST'),$this->get['path']));
		}

		// check if file is readable
		if(!$this->has_system_permission($current_path, array('r'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		if(!$handle = @opendir($current_path)) {
			$this->error(sprintf($this->lang('UNABLE_TO_OPEN_DIRECTORY'),$this->get['path']));
		} else {
			while (false !== ($file = readdir($handle))) {
				if($file != "." && $file != "..") {
					array_push($filesDir, $file);
				}
			}
			closedir($handle);

			// By default
			// Sorting files by name ('default' or 'NAME_DESC' cases from $this->config['options']['fileSorting']
			natcasesort($filesDir);

			foreach($filesDir as $file) {

				if(is_dir($current_path . $file)) {
					if(!in_array($file, $this->config['exclude']['unallowed_dirs']) && !preg_match( $this->config['exclude']['unallowed_dirs_REGEXP'], $file)) {

						$iconsFolder = $this->getUrl($this->config['icons']['path']);
						// check if file is writable and readable
						if(!$this->has_system_permission($current_path . $file, array('w', 'r'))) {
							$protected = 1;
							$previewPath = $iconsFolder . 'locked_' . $this->config['icons']['directory'];
						} else {
							$protected =0;
							$previewPath = $iconsFolder . $this->config['icons']['directory'];
						}

						$array[$this->get['path'] . $file . '/'] = array(
							'Path' => $this->get['path'] . $file . '/',
							'Filename' => $file,
							'File Type' => 'dir',
							'Protected' => $protected,
							'Preview' => $previewPath,
							'Properties' => array(
								'Date Created' => date($this->config['options']['dateFormat'], filectime($this->getFullPath($this->get['path'] . $file . '/'))),
								'Date Modified' => date($this->config['options']['dateFormat'], filemtime($this->getFullPath($this->get['path'] . $file . '/'))),
								'filemtime' => filemtime($this->getFullPath($this->get['path'] . $file . '/')),
								'Height' => null,
								'Width' => null,
								'Size' => null
							),
							'Error' => "",
							'Code' => 0
						);
					}
				} else if (!in_array($file, $this->config['exclude']['unallowed_files']) && !preg_match( $this->config['exclude']['unallowed_files_REGEXP'], $file)) {
					$this->item = array();
					$this->item['properties'] = $this->properties;
					$this->get_file_info($this->get['path'] . $file, true);


					if(!isset($this->params['type']) || (isset($this->params['type']) && strtolower($this->params['type'])=='images' && in_array(strtolower($this->item['filetype']),array_map('strtolower', $this->config['images']['imagesExt'])))) {
						if($this->config['upload']['imagesOnly']== false || ($this->config['upload']['imagesOnly']== true && in_array(strtolower($this->item['filetype']),array_map('strtolower', $this->config['images']['imagesExt'])))) {
							$array[$this->get['path'] . $file] = array(
								'Path' => $this->get['path'] . $file,
								'Filename' => $this->item['filename'],
								'File Type' => $this->item['filetype'],
								'Protected' => $this->item['protected'],
								'Preview' => $this->item['preview'],
								'Properties' => $this->item['properties'],
								'Error' => "",
								'Code' => 0
							);
						}
					}
				}
			}
		}

		$array = $this->sortFiles($array);

		return $array;
	}

    /**
     * Open and edit file - filemanager action
     * @return array
     */
	public function editfile()
    {
		$current_path = $this->getFullPath();

		// check if file is writable
		if(!$this->has_system_permission($current_path, array('w'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		if(!$this->has_permission('edit') || !$this->is_valid_path($current_path) || !$this->is_editable($current_path)) {
			$this->error("No way.");
		}

		$this->__log(__METHOD__ . ' - editing file '. $current_path);

		$content = file_get_contents($current_path);
		$content = htmlspecialchars($content);

		if($content === false) {
			$this->error(sprintf($this->lang('ERROR_OPENING_FILE')));
		}

		$array = array(
			'Error' => "",
			'Code' => 0,
			'Path' => $this->get['path'],
			'Content' => $this->formatPath($content)
		);

		return $array;
	}

    /**
     * Save data to file after editing - filemanager action
     */
	public function savefile()
    {
		$current_path = $this->getFullPath($this->post['path']);

		if(!$this->has_permission('edit') || !$this->is_valid_path($current_path) || !$this->is_editable($current_path)) {
			$this->error("No way.");
		}

		if(!$this->has_system_permission($current_path, array('w'))) {
			$this->error(sprintf($this->lang('ERROR_WRITING_PERM')));
		}

		$this->__log(__METHOD__ . ' - saving file '. $current_path);

		$content =  htmlspecialchars_decode($this->post['content']);
		$r = file_put_contents($current_path, $content, LOCK_EX);

		if(!is_numeric($r)) {
			$this->error(sprintf($this->lang('ERROR_SAVING_FILE')));
		}

		$array = array(
			'Error' => "",
			'Code' => 0,
			'Path' => $this->formatPath($this->post['path'])
		);

		return $array;
	}

    /**
     * Rename file or folder - filemanager action
     */
	public function rename()
    {
		$suffix = '';

		if(substr($this->get['old'], -1, 1) == '/') {
			$this->get['old'] = substr($this->get['old'], 0, (strlen($this->get['old'])-1));
			$suffix = '/';
		}
		$tmp = explode('/', $this->get['old']);
		$filename = $tmp[(sizeof($tmp)-1)];

		$newPath = str_replace('/' . $filename, '', $this->get['old']);
		$newName = $this->cleanString($this->get['new'], array('.', '-'));

		$old_file = $this->getFullPath($this->get['old']) . $suffix;
		$new_file = $this->getFullPath($newPath . '/' . $newName). $suffix;

		if(!$this->has_permission('rename') || !$this->is_valid_path($old_file)) {
			$this->error("No way.");
		}

		// forbid to change path during rename
		if(strrpos($this->get['new'], '/') !== false) {
			$this->error(sprintf($this->lang('FORBIDDEN_CHAR_SLASH')));
		}

		// check if file is writable
		if(!$this->has_system_permission($old_file, array('w'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if not requesting main FM userfiles folder
		if($this->is_root_folder($old_file)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// for file only - we check if the new given extension is allowed regarding the security Policy settings
		if(is_file($old_file) && $this->config['security']['allowChangeExtensions'] && !$this->is_allowed_file_type($new_file)) {
			$this->error(sprintf($this->lang('INVALID_FILE_TYPE')));
		}

		$this->__log(__METHOD__ . ' - renaming ' . $old_file . ' to ' . $new_file);

		if(file_exists($new_file)) {
			if($suffix == '/' && is_dir($new_file)) {
				$this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'), $newName));
			}
			if($suffix == '' && is_file($new_file)) {
				$this->error(sprintf($this->lang('FILE_ALREADY_EXISTS'), $newName));
			}
		}

		if(!rename($old_file, $new_file)) {
			if(is_dir($old_file)) {
				$this->error(sprintf($this->lang('ERROR_RENAMING_DIRECTORY'), $filename, $newName));
			} else {
				$this->error(sprintf($this->lang('ERROR_RENAMING_FILE'), $filename, $newName));
			}
		} else {
			// For image only - rename thumbnail if original image was successfully renamed
			if(!is_dir($new_file) && $this->is_image($new_file)) {
				$new_thumbnail = $this->get_thumbnail_path($new_file);
				$old_thumbnail = $this->get_thumbnail_path($old_file);
				if(file_exists($old_thumbnail)) {
					rename($old_thumbnail, $new_thumbnail);
				}
			}
		}

		$array = array(
			'Error' => "",
			'Code' => 0,
			'Old Path' => $this->formatPath($this->get['old'] . $suffix),
			'Old Name' => $filename,
			'New Path' => $this->formatPath($newPath . '/' . $newName . $suffix),
			'New Name' => $newName
		);
		return $array;
	}

    /**
     * Move file or folder - filemanager action
     */
	public function move()
    {
		$newPath = $this->get['new'] . '/';
		// dynamic fileroot dir must be used when enabled
		if($this->dynamic_fileroot != '') {
			$newPath = $this->dynamic_fileroot . '/' . $newPath;
		}

		$oldPath = $this->getFullPath($this->get['old']);

		// check if file is writable
		if(!$this->has_system_permission($oldPath, array('w'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')),true);
		}

		// check if not requesting main FM userfiles folder
		if($this->is_root_folder($oldPath)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')),true);
		}

		// old path
		$tmp = explode('/',trim($this->get['old'], '/'));
		$fileName = array_pop($tmp); // file name or new dir name
		$path = '/' . implode('/', $tmp) . '/';

		$this->cleanPath($newPath);
		$newPath = $this->expandPath($newPath, true);

		$newRelativePath = str_replace('./', '', $newPath);
		$newPath = $this->getFullPath($newPath);
		$newFullPath = $newPath . $fileName;

		if(!$this->has_permission('move') || !$this->is_valid_path($oldPath) || !$this->is_valid_path($newPath)) {
			$this->error("No way.");
		}

		// check if file already exists
		if (file_exists($newFullPath)) {
			if(is_dir($newFullPath)) {
				$this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'), rtrim($this->get['new'], '/') . '/' . $fileName));
			} else {
				$this->error(sprintf($this->lang('FILE_ALREADY_EXISTS'), rtrim($this->get['new'], '/') . '/' . $fileName));
			}
		}

		// create dir if not exists
		if (!file_exists($newPath)) {
			if(!mkdir($newPath,0755, true)) {
				$this->error(sprintf($this->lang('UNABLE_TO_CREATE_DIRECTORY'), $newPath));
			}
		}

		// should be retrieved before rename operation
		$old_thumbnail = $this->get_thumbnail_path($oldPath);

		// move file or folder
		if(!rename($oldPath, $newFullPath)) {
			if(is_dir($oldPath)) {
				$this->error(sprintf($this->lang('ERROR_RENAMING_DIRECTORY'), $path, $this->get['new']));
			} else {
				$this->error(sprintf($this->lang('ERROR_RENAMING_FILE'), $path . $fileName, $this->get['new']));
			}
		} else {
			// move thumbnail file or thumbnails folder if exists
			if(file_exists($old_thumbnail)) {
				$new_thumbnail = $this->get_thumbnail_path($newFullPath);
				// delete old thumbnail(s) if destination folder does not exist
				if(file_exists(dirname($new_thumbnail))) {
					rename($old_thumbnail, $new_thumbnail);
				} else {
					is_dir($old_thumbnail) ? $this->unlinkRecursive($old_thumbnail) : unlink($old_thumbnail);
				}
			}
		}

		$array = array(
			'Error' => "",
			'Code' => 0,
			'Old Path' => $this->formatPath($path),
			'Old Name' => $fileName,
			'New Path' => $this->formatPath($newRelativePath),
			'New Name' => $fileName,
			'Type' => is_dir($oldPath) ? 'dir' : 'file',
		);
		return $array;
	}

    /**
     * Delete existed file or folder - filemanager action
     */
	public function delete()
    {
		$current_path = $this->getFullPath();
		$thumbnail_path = $this->get_thumbnail_path($current_path);

		if(!$this->has_permission('delete') || !$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		// check if file is writable
		if(!$this->has_system_permission($current_path, array('w'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if not requesting main FM userfiles folder
		if($this->is_root_folder($current_path)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		if(is_dir($current_path)) {

			$this->unlinkRecursive($current_path);

			// delete thumbnails if exists
			$this->__log(__METHOD__ . ' - deleting thumbnails folder '. $thumbnail_path);
			if(file_exists($thumbnail_path)) {
				$this->unlinkRecursive($thumbnail_path);
			}

			$array = array(
				'Error' => "",
				'Code' => 0,
				'Path' => $this->formatPath($this->get['path'])
			);

			$this->__log(__METHOD__ . ' - deleting folder '. $current_path);
			return $array;

		} else if(file_exists($current_path)) {

			unlink($current_path);

			// delete thumbnails if exists
			$this->__log(__METHOD__ . ' - deleting thumbnail file '. $thumbnail_path);
			if(file_exists($thumbnail_path)) {
				unlink($thumbnail_path);
			}

			$array = array(
				'Error' => "",
				'Code' => 0,
				'Path' => $this->formatPath($this->get['path'])
			);

			$this->__log(__METHOD__ . ' - deleting file '. $current_path);
			return $array;

		} else {
			$this->error(sprintf($this->lang('INVALID_DIRECTORY_OR_FILE')));
		}
	}

    /**
     * Replace existed file - filemanager action
     */
	public function replace()
    {
		$this->setParams();
		$this->validateUploadedFile('fileR');

		// we check the given file has the same extension as the old one
		if(strtolower(pathinfo($_FILES['fileR']['name'], PATHINFO_EXTENSION)) != strtolower(pathinfo($this->post['newfilepath'], PATHINFO_EXTENSION))) {
			$this->error(sprintf($this->lang('ERROR_REPLACING_FILE') . ' '. pathinfo($this->post['newfilepath'], PATHINFO_EXTENSION)),true);
		}

		$current_path = $this->getFullPath($this->post['newfilepath']);

		// check if file is writable
		if(!$this->has_system_permission($current_path, array('w'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')), true);
		}

		if(!$this->has_permission('replace') || !$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		move_uploaded_file($_FILES['fileR']['tmp_name'], $current_path);

		// we delete thumbnail if file is image and thumbnail already
		if($this->is_image($current_path) && file_exists($this->get_thumbnail($current_path))) {
			unlink($this->get_thumbnail($current_path));
		}

		// automatically resize image if it's too big
		$imagePath = $current_path;
		if($this->is_image($imagePath) && $this->config['images']['resize']['enabled']) {
			if ($size = @getimagesize($imagePath)){
				if ($size[0] > $this->config['images']['resize']['maxWidth'] || $size[1] > $this->config['images']['resize']['maxHeight']) {
					$this->resizeImage($imagePath, $this->config['images']['resize']['maxWidth'], $this->config['images']['resize']['maxHeight']);
					$this->__log(__METHOD__ . ' - resizing image : '. $current_path);
				}
			}
		}

		chmod($current_path, 0644);

		$response = array(
			'Path' => dirname($this->post['newfilepath']),
			'Name' => basename($this->post['newfilepath']),
			'Error' => "",
			'Code' => 0
		);

		$this->__log(__METHOD__ . ' - replacing file '. $current_path);

		echo '<textarea>' . json_encode($response) . '</textarea>';
		die();
	}

    /**
     * Upload new file - filemanager action
     */
	public function add()
    {
		$this->setParams();
		$this->validateUploadedFile('newfile');

		$_FILES['newfile']['name'] = $this->cleanString($_FILES['newfile']['name'], array('.', '-'));

		$current_path = $this->getFullPath($this->post['currentpath']);

		if(!$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		// unless we are in overwrite mode, we need a unique file name
		if(!$this->config['upload']['overwrite']) {
			$_FILES['newfile']['name'] = $this->checkFilename($current_path,$_FILES['newfile']['name']);
		}

		move_uploaded_file($_FILES['newfile']['tmp_name'], $current_path . $_FILES['newfile']['name']);

		// automatically resize image if it's too big
		$imagePath = $current_path . $_FILES['newfile']['name'];
		if($this->is_image($imagePath) && $this->config['images']['resize']['enabled']) {
			if ($size = @getimagesize($imagePath)){
				if ($size[0] > $this->config['images']['resize']['maxWidth'] || $size[1] > $this->config['images']['resize']['maxHeight']) {
					$this->resizeImage($imagePath, $this->config['images']['resize']['maxWidth'], $this->config['images']['resize']['maxHeight']);
					$this->__log(__METHOD__ . ' - resizing image : '. $_FILES['newfile']['name']. ' into '. $current_path);
				}
			}
		}

		chmod($current_path . $_FILES['newfile']['name'], 0644);

		$response = array(
			'Path' => $this->post['currentpath'],
			'Name' => $_FILES['newfile']['name'],
			'Error' => "",
			'Code' => 0
		);

		$this->__log(__METHOD__ . ' - adding file '. $_FILES['newfile']['name']. ' into '. $current_path);

		echo '<textarea>' . json_encode($response) . '</textarea>';
		die();
	}

    /**
     * Create new folder - filemanager action
     * @return array
     */
	public function addfolder()
    {
		$current_path = $this->getFullPath();

		if(!$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}
		if(is_dir($current_path . $this->get['name'])) {
			$this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'),$this->get['name']));
		}

		$newdir = $this->cleanString($this->get['name']);
		if(!mkdir($current_path . $newdir,0755)) {
			$this->error(sprintf($this->lang('UNABLE_TO_CREATE_DIRECTORY'),$newdir));
		}
		$array = array(
			'Parent' => $this->get['path'],
			'Name' => $this->get['name'],
			'Error' => "",
			'Code' => 0
		);
		$this->__log(__METHOD__ . ' - adding folder '. $current_path . $newdir);

		return $array;
	}

	/**
	 * Creates a zip file from source to destination
	 * @param  string $source Source path for zip
	 * @param  string $destination Destination path for zip
	 * @param  string|boolean $flag OPTIONAL If true includes the folder also
	 * @return boolean
	 * @link 	 http://stackoverflow.com/questions/17584869/zip-main-folder-with-sub-folder-inside
	 */
	public function zipFile($source, $destination, $flag = '')
	{
		if (!extension_loaded('zip') || !file_exists($source)) {
			return false;
		}

		$zip = new ZipArchive();
		if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
			return false;
		}

		$source = str_replace('\\', '/', realpath($source));
		if($flag)
		{
			$flag = basename($source) . '/';
			//$zip->addEmptyDir(basename($source) . '/');
		}

		if (is_dir($source) === true)
		{
			// add file to prevent empty archive error on download
			$zip->addFromString('fm', "This archive has been generated by simogeo's Filemanager : https://github.com/simogeo/Filemanager/");

			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
			foreach ($files as $file)
			{
				$file = str_replace('\\', '/', realpath($file));

				if (is_dir($file) === true)
				{
					$zip->addEmptyDir(str_replace($source . '/', '', $flag.$file . '/'));
				}
				else if (is_file($file) === true)
				{
					$zip->addFromString(str_replace($source . '/', '', $flag.$file), file_get_contents($file));
				}
			}
		}
		else if (is_file($source) === true)
		{
			$zip->addFromString($flag.basename($source), file_get_contents($source));
		}

		return $zip->close();
	}

    /**
     * Download file - filemanager action
     */
	public function download()
    {
		$current_path = $this->getFullPath();

		if(!$this->has_permission('download') || !$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		// check if file is writable
		if(!$this->has_system_permission($current_path, array('w'))) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')),true);
		}

		// we check if extension is allowed regarding the security Policy settings
		if(is_file($current_path)) {
			if(!$this->is_allowed_file_type(basename($current_path))) {
				$this->error(sprintf($this->lang('INVALID_FILE_TYPE')),true);
			}

		} else {

			// check if permission is granted
			if(is_dir($current_path) && $this->config['security']['allowFolderDownload'] == false ) {
				$this->error(sprintf($this->lang('NOT_ALLOWED')),true);
			}

			// check if not requesting main FM userfiles folder
			if($this->is_root_folder($current_path)) {
				$this->error(sprintf($this->lang('NOT_ALLOWED')),true);
			}

			$destination_path = sys_get_temp_dir().'/'.uniqid().'.zip';

			// if Zip archive is created
			if($this->zipFile($current_path, $destination_path, true)) {
				$current_path = $destination_path;
			} else {
				$this->error($this->lang('ERROR_CREATING_ZIP'));
			}

		}

		if(isset($this->get['path']) && file_exists($current_path)) {
			header("Content-type: application/force-download");
			header('Content-Disposition: inline; filename="' . basename($current_path) . '"');
			header("Content-Transfer-Encoding: Binary");
			header("Content-length: ".filesize($current_path));
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename="' . basename($current_path) . '"');
			readfile($current_path);
			$this->__log(__METHOD__ . ' - downloading '. $current_path);
			exit();
		} else {
			$this->error(sprintf($this->lang('FILE_DOES_NOT_EXIST'),$current_path));
		}
	}

    /**
     * Preview file - filemanager action
     * @param bool $thumbnail Whether to generate image thumbnail
     */
	public function preview($thumbnail)
    {
		$current_path = $this->getFullPath();

		if(!$this->is_valid_path($current_path)) {
			$this->error("No way.");
		}

		if(isset($this->get['path']) && file_exists($current_path)) {

			// if $thumbnail is set to true we return the thumbnail
			if($this->config['options']['generateThumbnails'] == true && $thumbnail == true) {
				// get thumbnail (and create it if needed)
				$returned_path = $this->get_thumbnail($current_path);
			} else {
				$returned_path = $current_path;
			}

			header("Content-type: image/" . strtolower(pathinfo($returned_path, PATHINFO_EXTENSION)));
			header("Content-Transfer-Encoding: Binary");
			header("Content-length: ".filesize($returned_path));
			header('Content-Disposition: inline; filename="' . basename($returned_path) . '"');
			readfile($returned_path);

			exit();

		} else {
			$this->error(sprintf($this->lang('FILE_DOES_NOT_EXIST'), $current_path));
		}
	}

	public function getMaxUploadFileSize()
    {
		$max_upload = (int) ini_get('upload_max_filesize');
		$max_post = (int) ini_get('post_max_size');
		$memory_limit = (int) ini_get('memory_limit');

		$upload_mb = min($max_upload, $max_post, $memory_limit);

		$this->__log(__METHOD__ . ' - max upload file size is '. $upload_mb. 'Mb');

		return $upload_mb;
	}

	protected function setParams()
    {
		$tmp = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/');
		$tmp = explode('?',$tmp);
		$params = array();
		if(isset($tmp[1]) && $tmp[1]!='') {
			$params_tmp = explode('&',$tmp[1]);
			if(is_array($params_tmp)) {
				foreach($params_tmp as $value) {
					$tmp = explode('=',$value);
					if(isset($tmp[0]) && $tmp[0]!='' && isset($tmp[1]) && $tmp[1]!='') {
						$params[$tmp[0]] = $tmp[1];
					}
				}
			}
		}
		$this->params = $params;
	}

    protected function setPermissions()
    {
		$this->allowed_actions = $this->config['options']['capabilities'];

		if($this->config['edit']['enabled']) array_push($this->allowed_actions, 'edit');
	}

    /**
     * Check if system permission is granted
     * @param string $filepath
     * @param array $permissions
     * @return bool
     */
    protected function has_system_permission($filepath, $permissions)
    {
		if(in_array('r', $permissions)) {
			if(!is_readable($filepath)) return false;
		}
		if(in_array('w', $permissions)) {
			if(!is_writable($filepath)) return false;
		}

		return true;
	}

    /**
     * Create array with file properties
     * @param string $path
     * @param bool $thumbnail
     */
	protected function get_file_info($path = '', $thumbnail = false)
    {
		// DO NOT  rawurlencode() since $relative_path it
		// is used for displaying name file
		if($path=='') {
			$relative_path = $this->get['path'];
		} else {
			$relative_path = $path;
		}
		$current_path = $this->getFullPath($relative_path);

		$tmp = explode('/', $relative_path);
		$this->item['filename'] = $tmp[(sizeof($tmp)-1)];

		$tmp = explode('.',$this->item['filename']);
		$this->item['filetype'] = $tmp[(sizeof($tmp)-1)];
		$this->item['filemtime'] = filemtime($current_path);
		$this->item['filectime'] = filectime($current_path);
		$iconsFolder = $this->getUrl($this->config['icons']['path']);

		// check if file is writable and readable
		if(!$this->has_system_permission($current_path, array('w', 'r'))) {
			$this->item['protected'] = 1;
			$this->item['preview'] = $iconsFolder . 'locked_' . $this->config['icons']['default'];
			// prevent Internal Server Error HTTP_CODE 500 on non readable files/folders
			// without returning errors
			return;

		}	else {
			$this->item['protected'] = 0;
			$this->item['preview'] = $iconsFolder . $this->config['icons']['default'];
		}

		if(is_dir($current_path)) {

			$this->item['preview'] = $iconsFolder . $this->config['icons']['directory'];

		} else if($this->config['options']['showThumbs'] && in_array(strtolower($this->item['filetype']), array_map('strtolower', $this->config['images']['imagesExt']))) {

			// svg should not be previewed as raster formats images
			if($this->item['filetype'] == 'svg') {
				$this->item['preview'] = $relative_path;
			} else {
				$this->item['preview'] = $this->connector_script_url . '?mode=preview&path='. rawurlencode($relative_path).'&'. time();
				if($thumbnail) $this->item['preview'] .= '&thumbnail=true';
			}
			//if(isset($get['getsize']) && $get['getsize']=='true') {
			$this->item['properties']['Size'] = filesize($current_path);
			if ($this->item['properties']['Size']) {
				list($width, $height, $type, $attr) = getimagesize($current_path);
			} else {
				$this->item['properties']['Size'] = 0;
				list($width, $height) = array(0, 0);
			}
			$this->item['properties']['Height'] = $height;
			$this->item['properties']['Width'] = $width;
			$this->item['properties']['Size'] = filesize($current_path);
			//}

		} else if(file_exists($this->root_path . '/' . $this->config['icons']['path'] . strtolower($this->item['filetype']) . '.png')) {

			$this->item['preview'] = $iconsFolder . strtolower($this->item['filetype']) . '.png';
			$this->item['properties']['Size'] = filesize($current_path);
			if (!$this->item['properties']['Size']) $this->item['properties']['Size'] = 0;

		}

		$this->item['properties']['Date Modified'] = date($this->config['options']['dateFormat'], $this->item['filemtime']);
		$this->item['properties']['filemtime'] = filemtime($current_path);
		//$return['properties']['Date Created'] = $this->config['options']['dateFormat'], $return['filectime']); // PHP cannot get create timestamp
	}

    /**
     * Return full path to file
     * @param string $path
     * @return mixed|string
     */
	protected function getFullPath($path = '')
    {
		if($path == '') {
			if(isset($this->get['path'])) $path = $this->get['path'];
		}

		if($this->config['options']['fileRoot'] !== false) {
			$full_path = $this->doc_root . rawurldecode(str_replace ( $this->doc_root , '' , $path));
			if($this->dynamic_fileroot != '') {
				$full_path = $this->doc_root . rawurldecode(str_replace ( $this->dynamic_fileroot , '' , $path));
				// $dynPart = str_replace($_SERVER['DOCUMENT_ROOT'], '', $this->path_to_files); // instruction could replace the line above
				// $full_path = $this->path_to_files . rawurldecode(str_replace ( $dynPart , '' , $path)); // instruction could replace the line above
			}
		} else {
			$full_path = $this->doc_root . rawurldecode($path);
		}
		$this->cleanPath($full_path);

		// $this->__log("getFullPath() returned path : " . $full_path);

		return $full_path;
	}

	/**
	 * Format path regarding the initial configuration
	 * @param string $path
     * @return mixed
	 */
    protected function formatPath($path)
    {
		if($this->dynamic_fileroot != '') {
			$a = explode($this->separator, $path);
			return end($a);
		} else {
			return $path;
		}
	}

    protected function sortFiles($array)
    {
		// handle 'NAME_ASC'
		if($this->config['options']['fileSorting'] == 'NAME_ASC') {
			$array = array_reverse($array);
		}

		// handle 'TYPE_ASC' and 'TYPE_DESC'
		if(strpos($this->config['options']['fileSorting'], 'TYPE_') !== false || $this->config['options']['fileSorting'] == 'default') {

			$a = array();
			$b = array();

			foreach ($array as $key=>$item){
				if(strcmp($item["File Type"], "dir") == 0) {
					$a[$key]=$item;
				}else{
					$b[$key]=$item;
				}
			}

			if($this->config['options']['fileSorting'] == 'TYPE_ASC') {
				$array = array_merge($a, $b);
			}

			if($this->config['options']['fileSorting'] == 'TYPE_DESC' || $this->config['options']['fileSorting'] == 'default') {
				$array = array_merge($b, $a);
			}
		}

		// handle 'MODIFIED_ASC' and 'MODIFIED_DESC'
		if(strpos($this->config['options']['fileSorting'], 'MODIFIED_') !== false) {

			$modified_order_array = array();  // new array as a column to sort collector

			foreach ($array as $item) {
				$modified_order_array[] = $item['Properties']['filemtime'];
			}

			if($this->config['options']['fileSorting'] == 'MODIFIED_ASC') {
				array_multisort($modified_order_array, SORT_ASC, $array);
			}
			if($this->config['options']['fileSorting'] == 'MODIFIED_DESC') {
				array_multisort($modified_order_array, SORT_DESC, $array);
			}
			return $array;

		}

		return $array;
	}

    /**
     * Check whether path is valid by comparing paths
     * @param string $path
     * @return bool
     */
	protected function is_valid_path($path)
    {
        $rp_substr = substr(realpath($path) . DIRECTORY_SEPARATOR, 0, strlen(realpath($this->path_to_files))) . DIRECTORY_SEPARATOR;
        $rp_files = realpath($this->path_to_files) . DIRECTORY_SEPARATOR;

		// handle better symlinks & network path - issue #448
		$pattern = array('/\\\\+/', '/\/+/');
		$replacement = array('\\\\', '/');
		$rp_substr = preg_replace($pattern, $replacement, $rp_substr);
		$rp_files = preg_replace($pattern, $replacement, $rp_files);

		$this->__log('substr path_to_files : ' . $rp_substr);
		$this->__log('path_to_files : ' . $rp_files);

		return $rp_substr == $rp_files;
	}

    /**
     * Delete folder recursive
     * @param string $dir
     * @param bool $deleteRootToo
     */
    protected function unlinkRecursive($dir, $deleteRootToo = true)
    {
		if(!$dh = @opendir($dir)) {
			return;
		}
		while (false !== ($obj = readdir($dh))) {
			if($obj == '.' || $obj == '..') {
				continue;
			}

			if (!@unlink($dir . '/' . $obj)) {
				$this->unlinkRecursive($dir.'/'.$obj, true);
			}
		}

		closedir($dh);

		if ($deleteRootToo) {
			@rmdir($dir);
		}

		return;
	}

	/**
	 * Check if extension is allowed regarding the security Policy / Restrictions settings
	 * @param string $file
     * @return bool
	 */
    protected function is_allowed_file_type($file)
    {
		$path_parts = pathinfo($file);

		// if there is no extension
		if (!isset($path_parts['extension'])) {
			// we check if no extension file are allowed
			return (bool)$this->config['security']['allowNoExtension'];
		}

		$exts = array_map('strtolower', $this->config['security']['uploadRestrictions']);

		if($this->config['security']['uploadPolicy'] == 'DISALLOW_ALL') {

			if(!in_array(strtolower($path_parts['extension']), $exts))
				return false;
		}
		if($this->config['security']['uploadPolicy'] == 'ALLOW_ALL') {

			if(in_array(strtolower($path_parts['extension']), $exts))
				return false;
		}

		return true;
	}

    /**
     * Clean string to retrieve correct filename
     * @param string|array $string
     * @param array $allowed
     * @return array|mixed
     */
	protected function cleanString($string, $allowed = array())
    {
		$allow = null;

		if(!empty($allowed)) {
			foreach ($allowed as $value) {
				$allow .= "\\$value";
			}
		}

		if(is_array($string)) {
			$cleaned = array();
			foreach ($string as $key => $clean) {
				$cleaned[$key] = $this->transliterateString($clean, $allow);
			}
		} else {
			$cleaned = $this->transliterateString($string, $allow);
		}

		return $cleaned;
	}

	/**
	 * Clean path string to remove multiple slashes, etc.
	 * @param string $string
	 */
	protected function cleanPath(&$string)
    {
		// remove multiple slashes
		$string = preg_replace('#/+#', '/', $string);
		//str_replace("//", "/", $string);
	}

    /**
     * Transliterate string to latin chars
     * @param string|array $string
     * @param array $allowed
     * @return mixed
     */
    protected function transliterateString($string, $allowed)
	{
		$mapping = array(
			'Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z', 'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c',
			'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
			'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
			'Õ'=>'O', 'Ö'=>'O', 'Ő'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ű'=>'U', 'Ý'=>'Y',
			'Þ'=>'B', 'ß'=>'Ss','à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
			'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n',
			'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ő'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'ű'=>'u',
			'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r',
		);

		$clean = strtr($string, $mapping);
		// replace chars which are not related to any language
		$clean = strtr($clean, array(' '=>'_', "'"=>'_', '/'=>''));

		if($this->config['options']['chars_only_latin'] == true) {
			if(extension_loaded('intl') === true) {
				$options = 'Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC;';
				$clean = transliterator_transliterate($options, $string);
			}
			$clean = preg_replace("/[^{$allowed}_a-zA-Z0-9]/u", '', $clean);
			// $clean = preg_replace("/[^{$allow}_a-zA-Z0-9\x{0430}-\x{044F}\x{0410}-\x{042F}]/u", '', $clean); // allow only latin alphabet with cyrillic
		}

		return preg_replace('/[_]+/', '_', $clean); // remove double underscore
	}

	/**
	 * Checking if permission is set or not for a given action
	 * @param string $action
	 * @return boolean
	 */
    protected function has_permission($action)
    {
		return in_array($action, $this->allowed_actions);
	}

	/**
	 * Return Thumbnail path from given path, works for both file and dir path
	 * @param string $path
     * @return string
	 */
	protected function get_thumbnail_path($path)
    {
		$pos = strrpos($path, $this->separator);
		$a[0] = substr($path,0,$pos);
		$a[1] = substr($path,$pos+strlen($this->separator));

		$path_parts = pathinfo($path);
		$thumbnail_path = $a[0] . $this->separator . '/' . $this->cachefolder . '/';

		if(is_dir($path)) {
			$thumbnail_fullpath = $thumbnail_path . end($a) . '/';
		} else {
			$thumbnail_name = $path_parts['filename'] . '_' . $this->thumbnail_width . 'x' . $this->thumbnail_height . 'px.' . $path_parts['extension'];
			$thumbnail_fullpath = $thumbnail_path . dirname(end($a)) . '/' . $thumbnail_name;
		}
		$this->cleanPath($thumbnail_fullpath);

		return $thumbnail_fullpath;
	}

	/**
	 * For debugging just call the direct URL:
	 * http://localhost/Filemanager/connectors/php/filemanager.php?mode=preview&path=%2FFilemanager%2Fuserfiles%2FMy%20folder3%2Fblanches_neiges.jPg&thumbnail=true
	 * @param string $path
     * @return string
	 */
	protected function get_thumbnail($path)
    {
		$thumbnail_fullpath = $this->get_thumbnail_path($path);

		// if thumbnail does not exist we generate it or cacheThumbnail is set to false
		if(!file_exists($thumbnail_fullpath) || $this->config['options']['cacheThumbnails'] == false) {

			// create folder if it does not exist
			if(!file_exists(dirname($thumbnail_fullpath))) {
				mkdir(dirname($thumbnail_fullpath), 0755, true);
			}
			$this->createThumbnail($path, $thumbnail_fullpath, $this->thumbnail_width, $this->thumbnail_height);
			$this->__log(__METHOD__ . ' - generating thumbnail :  '. $thumbnail_fullpath);
		}

		return $thumbnail_fullpath;
	}

    /**
     * Sanitize global vars: $_GET, $_POST
     * @param string $var
     * @return mixed|string
     */
	protected function sanitize($var)
    {
		$sanitized = strip_tags($var);
		$sanitized = str_replace('http://', '', $sanitized);
		$sanitized = str_replace('https://', '', $sanitized);
		$sanitized = str_replace('../', '', $sanitized);

		return $sanitized;
	}

    /**
     * Check whether filename is unique, otherwise build unique one
     * @param string $path
     * @param string $filename
     * @param string $i
     * @return mixed
     */
	protected function checkFilename($path, $filename, $i = '')
    {
		if(!file_exists($path . $filename)) {
			return $filename;
		} else {
			$_i = $i;
			$tmp = explode(/*$this->config['upload']['suffix'] . */$i . '.', $filename);
			if($i=='') {
				$i=1;
			} else {
				$i++;
			}
			$filename = str_replace($_i . '.' . $tmp[(sizeof($tmp)-1)],$i . '.' . $tmp[(sizeof($tmp)-1)], $filename);
			return $this->checkFilename($path, $filename, $i);
		}
	}

    /**
     * Load using "langCode" var passed into URL if present and if exists
     * Otherwise use default configuration var.
     */
	protected function loadLanguageFile()
    {
		$lang = $this->config['options']['culture'];
		if(isset($this->params['langCode']) && in_array($this->params['langCode'], $this->languages)) $lang = $this->params['langCode'];

		if(file_exists($this->root_path . '/scripts/languages/'.$lang.'.js')) {
			$stream =file_get_contents($this->root_path . '/scripts/languages/'.$lang.'.js');
			$this->language = json_decode($stream, true);
		} else {
			$l = substr($lang,0,2); // we try with 2 chars language file
			if(file_exists($this->root_path. '/scripts/languages/'.$l.'.js')) {
				$stream = file_get_contents($this->root_path . '/scripts/languages/'.$l.'.js');
				$this->language = json_decode($stream, true);
			} else {
				// we include default language file
				$stream = file_get_contents($this->root_path . '/scripts/languages/'.$this->config['options']['culture'].'.js');
				$this->language = json_decode($stream, true);
			}
		}
	}

    /**
     * Prepare available languages
     */
	protected function availableLanguages()
    {
		if ($handle = opendir($this->root_path . '/scripts/languages/')) {
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != "..") {
					array_push($this->languages, pathinfo($file, PATHINFO_FILENAME));
				}
			}
			closedir($handle);
		}
	}

    /**
     * Check whether the file is image
     * @param string $path
     * @return bool
     */
	protected function is_image($path)
    {
		if(!is_array($a = @getimagesize($path))) {
			return false;
		}
		$image_type = $a[2];

		return in_array($image_type , array(IMAGETYPE_GIF , IMAGETYPE_JPEG ,IMAGETYPE_PNG , IMAGETYPE_BMP));
	}

    /**
     * Check whether the folder is root
     * @param string $path
     * @return bool
     */
	protected function is_root_folder($path)
    {
		return rtrim($this->path_to_files, '/') == rtrim($path, '/');
	}

    /**
     * Check whether the file could be edited regarding configuration setup
     * @param string $file
     * @return bool
     */
	protected function is_editable($file)
    {
		$path_parts = pathinfo($file);
		$exts = array_map('strtolower', $this->config['edit']['editExt']);

		return in_array($path_parts['extension'], $exts);
	}

    /**
     * Return user IP address
     * @return mixed
     */
	protected function get_user_ip()
	{
		$client  = @$_SERVER['HTTP_CLIENT_IP'];
		$forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
		$remote  = $_SERVER['REMOTE_ADDR'];

		if(filter_var($client, FILTER_VALIDATE_IP))
		{
			$ip = $client;
		}
		elseif(filter_var($forward, FILTER_VALIDATE_IP))
		{
			$ip = $forward;
		}
		else
		{
			$ip = $remote;
		}

		return $ip;
	}

    /**
     * Write log to file
     * @param string $msg
     */
	protected function __log($msg)
    {
		if($this->logger == true) {
			$fp = fopen($this->logfile, "a");
			$str = "[" . date("d/m/Y h:i:s", time()) . "]#".  $this->get_user_ip() . "#" . $msg;
			fwrite($fp, $str . PHP_EOL);
			fclose($fp);
		}
	}

	public function enableLog($logfile = '')
    {
		$this->logger = true;

		if($logfile != '') {
			$this->logfile = $logfile;
		}

		$this->__log(__METHOD__ . ' - Log enabled (in '. $this->logfile. ' file)');
	}

	public function disableLog()
    {
		$this->logger = false;

		$this->__log(__METHOD__ . ' - Log disabled');
	}

	/**
	 * Remove "../" from path
	 * @param string $path Path to be converted
	 * @param bool $clean If dir names should be cleaned
	 * @return string or false in case of error (as exception are not used here)
	 */
	public function expandPath($path, $clean = false)
	{
		$todo  = explode('/', $path);
		$fullPath = array();

		foreach ($todo as $dir) {
			if ($dir == '..') {
				$element = array_pop($fullPath);
				if (is_null($element)) {
					return false;
				}
			} else {
				if ($clean) {
					$dir = $this->cleanString($dir);
				}
				array_push($fullPath, $dir);
			}
		}
		return implode('/', $fullPath);
	}

	/**
	 * Check whether file is uploaded and valid
	 * @param string $uploadName
	 */
	protected function validateUploadedFile($uploadName)
	{
		if(!isset($_FILES[$uploadName]) || !is_uploaded_file($_FILES[$uploadName]['tmp_name'])) {

			// if fileSize limit set by the user is greater than size allowed in php.ini file, we apply server restrictions
			// and log a warning into file
			if($this->config['upload']['fileSizeLimit'] > $this->getMaxUploadFileSize()) {
				$this->__log(__METHOD__ . ' [WARNING] : file size limit set by user is greater than size allowed in php.ini file : ' . $this->config['upload']['fileSizeLimit'] . $this->lang('mb') . ' > ' . $this->getMaxUploadFileSize() . $this->lang('mb') . '.');
				$this->config['upload']['fileSizeLimit'] = $this->getMaxUploadFileSize();
				$this->error(sprintf($this->lang('UPLOAD_FILES_SMALLER_THAN'),$this->config['upload']['fileSizeLimit'] . $this->lang('mb')),true);
			}

			$this->error(sprintf($this->lang('INVALID_FILE_UPLOAD') . ' '. sprintf($this->lang('UPLOAD_FILES_SMALLER_THAN'),$this->config['upload']['fileSizeLimit'] . $this->lang('mb'))),true);
		}

		// we determine max upload size if not set
		if($this->config['upload']['fileSizeLimit'] == 'auto') {
			$this->config['upload']['fileSizeLimit'] = $this->getMaxUploadFileSize();
		}

		if($_FILES[$uploadName]['size'] > ($this->config['upload']['fileSizeLimit'] * 1024 * 1024)) {
			$this->error(sprintf($this->lang('UPLOAD_FILES_SMALLER_THAN'),$this->config['upload']['fileSizeLimit'] . $this->lang('mb')),true);
		}

		// we check if extension is allowed regarding the security Policy settings
		if(!$this->is_allowed_file_type($_FILES[$uploadName]['name'])) {
			$this->error(sprintf($this->lang('INVALID_FILE_TYPE')),true);
		}

		// we check if only images are allowed
		if($this->config['upload']['imagesOnly'] || (isset($this->params['type']) && strtolower($this->params['type'])=='images')) {
			if(!($size = @getimagesize($_FILES[$uploadName]['tmp_name']))){
				$this->error(sprintf($this->lang('UPLOAD_IMAGES_ONLY')),true);
			}
			if(!in_array($size[2], array(1, 2, 3, 7, 8))) {
				$this->error(sprintf($this->lang('UPLOAD_IMAGES_TYPE_JPEG_GIF_PNG')),true);
			}
		}
	}

	/**
	 * Creates URL to asset based on it relative path
	 * @param $path
	 * @return string
	 */
	protected function getUrl($path)
	{
		if(isset($this->config['root_url']) && strpos($path, '/') !== 0 && $this->config['root_url']) {
			$url = $this->config['root_url'] . '/' . $path;
			$this->cleanPath($url);
			return $url;
		}
		return $path;
	}

	protected function resizeImage($imagePath, $width, $height)
	{
		require_once('./inc/wideimage/lib/WideImage.php');

		$image = WideImage::load($imagePath);
		$resized = $image->resize($width, $height, 'inside');
		$resized->saveToFile($imagePath);
	}

	protected function createThumbnail($imagePath, $thumbnailPath, $width, $height)
	{
		require_once('./inc/wideimage/lib/WideImage.php');

		$image = WideImage::load($imagePath);
		$resized = $image->resize($width, $height, 'outside')->crop('center', 'center', $width, $height);
		$resized->saveToFile($thumbnailPath);
	}
}