<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

$tmp = Gdn::factoryOverwrite(TRUE);
Gdn::factoryInstall('CssCacher', 'Gdn_CssThemes', __FILE__);
Gdn::factoryOverwrite($tmp);
unset($tmp);


class CssThemes extends Gdn_Plugin {
	/// Constants ///
	/**
	 * The Regex to capture themeable colors.
	 *
	 * This will capture a css color in four groups:
	 * (Color)(CommentStart)(Name)(CommentEnd)
	 *
	 * @var string
	 */
	const RegEx = '/(#[0-9a-fA-F]{3,6})(\s*\/\*\s*)([^\*]*?)(\s*\*\/)/';
	const RegEx2 = '/#([0-9a-fA-F]{3,6})/';
	const UrlRegEx = '/(url\s*\([\'"]?)([\w\.]+?)(\.\w+)([\'"]?\s*\)\s*)(\/\*\s*NoFollow\s*\*\/\s*)?/';

	/// Properties ///

	public $MissingSettings = [];

	protected $_OrignialPath;
	protected $_AppName;

	public $ThemeSettings = NULL;

	/// Methods ///

	public function applyTheme($originalPath, $cachePath, $insertNames = TRUE) {
		// Get the theme settings.
		$sQL = Gdn::sql();
		if(is_null($this->ThemeSettings)) {
			$data = $sQL->get('ThemeSetting')->resultArray();
			$data = consolidateArrayValuesByKey($data, 'Name', 'Setting', '');
			$this->ThemeSettings = $data;
		}

		$css = file_get_contents($originalPath);
		// Process the urls.
		$css = preg_replace_callback(self::UrlRegEx, [$this, '_ApplyUrl'], $css);

		// Go through the css and replace its colors with the theme colors.
		$css = preg_replace_callback(self::RegEx, [$this, '_ApplyThemeSetting'], $css);

		// Insert the missing settings into the database.
		if($insertNames) {
			foreach($this->MissingSettings as $name => $setting) {
				$sQL->insert('ThemeSetting', ['Name' => $name, 'Setting' => $setting]);
			}
			$this->MissingSettings = [];
		}

		// Save the theme to the cache path.
		file_put_contents($cachePath, $css);
		return $cachePath;
	}

	protected function _ApplyThemeSetting($match) {
		$setting = $match[1];
		$name = $match[3];

		if(array_key_exists($name, $this->ThemeSettings)) {
			$setting = $this->ThemeSettings[$name];
		} else {
			$this->ThemeSettings[$name] = $setting;
			$this->MissingSettings[$name] = $setting;
		}

		$result = $setting.$match[2].$name.$match[4];

		return $result;
	}

	protected function _ApplyImport($match) {
		$noFollow = arrayValue(4, $match);
		$url = $match[2];

		if($noFollow !== FALSE) {
			// Don't apply the theme to this import.
			$originalAssetPath = str_replace([PATH_ROOT, DS], ['', '/'], $this->_OrignialPath);
			$url = asset(combinePaths([dirname($originalAssetPath), $url], '/'));
		} else {
			// Also parse the import.
			$orignalPath = $this->_OrignialPath;
			$importPath = combinePaths([dirname($orignalPath), $url]);
			$url = $this->get($importPath, $this->_AppName);
			$url = str_replace([PATH_ROOT, DS], ['', '/'], $url);
			$url = asset($url);

			$this->_OrignalPath = $orignalPath;
		}


		$result = $match[1].$url.$match[3];
		return $result;
	}

	protected function _ApplyUrl($match) {
		$noFollow = arrayValue(5, $match);
		$url = $match[2];
		$extension = $match[3];

		if($noFollow !== FALSE || strcasecmp($extension, '.css') != 0) {
			// Don't apply the theme to this import.
			$originalAssetPath = str_replace([PATH_ROOT, DS], ['', '/'], $this->_OrignialPath);
			$url = asset(combinePaths([dirname($originalAssetPath), $url.$extension], '/'));
		} else {
			// Cache the css too.
			$orignalPath = $this->_OrignialPath;
			$importPath = combinePaths([dirname($orignalPath), $url.$extension]);
			$url = $this->get($importPath, $this->_AppName);
			$url = str_replace([PATH_ROOT, DS], ['', '/'], $url);
			$url = asset($url);

			$this->_OrignalPath = $orignalPath;
		}

		$result = $match[1].$url.$match[4];
		return $result;
	}

	public function get($originalPath, $appName) {
		if(!file_exists($originalPath))
			return FALSE;

		$this->_OrignialPath = $originalPath;
		$this->_AppName = $appName;

		$result = $originalPath;

		$filename = basename($originalPath);
		$cachePath = PATH_CACHE.DS.'css'.DS.$appName.'_'.$filename;

		if(!file_exists($cachePath) || filemtime($originalPath) > filemtime($cachePath)) {
			$css = file_get_contents($originalPath);

			$result = $this->applyTheme($originalPath, $cachePath);
		} else {
			$result = $cachePath;
		}

		return $result;
	}

	public function getNames($css, $insertNames = FALSE) {
		$result = [];

		if(preg_match_all(self::RegEx, $css, $matches)) {
			foreach($matches as $match) {
				$result[$match[1]] = $match[0];
			}
		}
		// Insert all of the names into the database.
		if(count($result) > 0) {
			$sQL = Gdn::sql();
			// Get the existing names.
			$insert = $result;


			// Insert the necessary settings.
			if($insertNames) {
				foreach($insert as $name => $setting) {
					$sQL->insert('ThemeSetting', ['Name' => $name, 'Setting' => $setting]);
				}
			}
		}

		return $result;
	}

	public function base_getAppSettingsMenuItems_handler($sender) {
      $menu = $sender->EventArguments['SideMenu'];
		$menu->addLink('Add-ons', 'Colors', 'plugin/cssthemes', 'Garden.Themes.Manage');
	}

	public function pluginController_colors_create($sender) {
		$sender->Form = Gdn::factory('Form');

		$this->Colors = [];

		$this->parseCss(PATH_APPLICATIONS);
		//$this->parseCss(PATH_THEMES);

		asort($this->Colors);
		$sender->Colors = $this->Colors;

		// Add the javascript & css.
		//$Sender->Head->addScript('/plugins/cssthemes/colorpicker.js');
		//$Sender->Head->addScript('/plugins/cssthemes/cssthemes.js');
		$sender->Head->addCss('/plugins/cssthemes/colorpicker.css');
		$sender->Head->addCss('/plugins/cssthemes/cssthemes.css');

		$sender->View = $this->getView('colors.php');
		$sender->render();
	}

	public function parseCss($path) {
		// Look for all of the css files in the path.
		$cssPaths = glob($path.DS.'*.css');
		if($cssPaths) {
			foreach($cssPaths as $cssPath) {
				//echo $CssPath, "<br />\n";
				$css = file_get_contents($cssPath);
				// Process the urls.
				//$Css = preg_replace_callback(self::UrlRegEx, array($this, '_ApplyUrl'), $Css);

				// Go through the css and replace its colors with the theme colors.
				$css = preg_replace_callback(self::RegEx2, [$this, 'GetColors'], $css);

			}
		}

		// Look for all of the subdirectories.
		$paths = glob($path.DS.'*', GLOB_ONLYDIR);
		if($paths) {
			foreach($paths as $path) {
				if(in_array(strrchr($path, DS), [DS.'vforg', DS.'vfcom']))
					continue;
				$this->parseCss($path);
			}
		}
	}

	public function rGB($color) {
		return [hexdec(substr($color, 0, 2)), hexdec(substr($color, 2, 2)), hexdec(substr($color, 4, 2))];
	}

	public function getColors($match) {
		$color = strtolower($match[1]);
		if(strlen($color) == 3)
			$color = str_repeat(substr($color, 0, 1), 2).str_repeat(substr($color, 1, 1), 2).str_repeat(substr($color, 2, 1), 2);

		list($h, $s, $v) = $this->rGB2HSB(hexdec(substr($color, 0, 2)), hexdec(substr($color, 2, 2)), hexdec(substr($color, 4, 2)));

		if($s < .2) {
			$s = 0;
			$h = 1000;
		}
		$h2 = $h / 72.0;

		$hSV = sprintf('%04d,%04d,%04d', round($h2), $v * 1000, $s * 1000);

		$this->Colors[$color] = $hSV;

		return implode($match);
	}

	function rGB2HSB($r, $g = NULL, $b = NULL) {
		if(is_null($g)) {
			list($r, $g, $b) = (array)$r;
		}

		$r /= 255;
		$g /= 255;
		$b /= 255;

		$h = $s = $v = 0;
		$min = min($r, $g, $b);
		$max = max($r, $g, $b);

		$v = $max;
		if($v == 0)
			return [1000, $s, $v];

		$r /= $v;
		$g /= $v;
		$b /= $v;
		$min = min($r, $g, $b);
		$max = max($r, $g, $b);

		$s = $max - $min;
		if($s == 0)
			return [1000, $s, $v];

		$r = ($r - $min) / ($max - $min);
		$g = ($g - $min) / ($max - $min);
		$b = ($b - $min) / ($max - $min);

		if($max == $r) {
			$h = 60 * ($g - $b);
			if($h < 0) $h += 360;
		} elseif($max == $g)
			$h = 120 + 60 * ($b - $r);
		else
			$h = 240 + 60 * ($r - $g);

		return [$h, $s, $v];
	}

	/**
	 * @package $sender Gdn_Controller
	 */
	public function pluginController_cssThemes_create($sender) {
		$sender->Form = Gdn::factory('Form');
		$model = new Gdn_Model('ThemeSetting');
		$sender->Form->setModel($model);

		if($sender->Form->authenticatedPostBack() === FALSE) {
			// Grab the colors.
			$data = $model->get();
			//$Data = consolidateArrayValuesByKey($Data->resultArray(), 'Name', 'Setting');
			$sender->setData('ThemeSettings', $data->resultArray());
			//$Sender->Form->setData($Data);
		} else {
			$data = $sender->Form->formDataSet();

			// Update the database.
			$sQL = Gdn::sql();
			foreach($data as $row) {
				$sQL->put(
					'ThemeSetting',
					['Setting' => $row['Setting']],
					['Name' => $row['Name']]);
			}

			// Clear out the css cache.
			$files = safeGlob(PATH_CACHE.DS.'css'.DS.'*.css');
			foreach($files as $file) {
				unlink($file);
			}

			$sender->setData('ThemeSettings', $data);
			$sender->StatusMessage = t('Your changes have been saved.');
		}

		// Add the javascript & css.
		$sender->Head->addScript('/plugins/cssthemes/colorpicker.js');
		$sender->Head->addScript('/plugins/cssthemes/cssthemes.js');
		$sender->Head->addCss('/plugins/cssthemes/colorpicker.css');
		$sender->Head->addCss('/plugins/cssthemes/cssthemes.css');

		// Add the side module.
      $sender->addSideMenu('/plugin/cssthemes');

		$sender->View = $this->getView('cssthemes.php');
		$sender->render();
	}

	public function setup() {
		if (!file_exists(PATH_CACHE.DS.'css')) mkdir(PATH_CACHE.DS.'css');

		// Setup the theme table.
		$st = Gdn::structure();
		$st->table('bThemeSetting')
			->column('Name', 'varchar(50)', FALSE, 'primary')
			->column('Setting', 'varchar(50)')
			->set(FALSE, FALSE);

		// Insert default values.
		$st->Database->query('insert '.$st->Database->DatabasePrefix.'bThemeSetting (Name, Setting) values '.
		"('Banner Background Color', '#44c7f4'),
		('Banner Font Color', '#fff'),
		('Banner Font Shadow Color', '#30ACD6'),
		('Banner Hover Font Color', '#f3fcff'),
		('Body Alt Background Color', '#f8f8f8'),
		('Body Background Color', '#ffffff'),
		('Body Default Font Color', '#000'),
		('Body Heading Font Color', '#000'),
		('Body Hover Font Color', '#ff0084'),
		('Body Line Color', '#eee'),
		('Body Link Font Color', '#2786c2'),
		('Body Subheading Font Color', '#6C6C6C'),
		('Body Text Font Color', '#555'),
		('Discussion My Background Color', '#F5FCFF'),
		('Discussion New Background Color', '#ffd'),
		('Menu Background Color', '#44c7f4'),
		('Menu Font Color', '#fff'),
		('Menu Hover Background Color', '#28bcef'),
		('Menu Hover Font Color', '#fff'),
		('Meta Font Color', '#2b2d33'),
		('Meta Label Font Color', '#80828c'),
		('Panel Background Color', '#E9F9FF'),
		('Panel Font Color', '#2786C2'),
		('Panel Hover Font Color', '#e9f9ff'),
		('Panel Inlay Background Color', '#f0fbff'),
		('Panel Inlay Border Color', '#caf0fe'),
		('Panel Inlay Font Color', '#0766a2'),
		('Panel Selected Background Color', '#fff'),
		('Panel Selected Font Color', '#ff0084')");
	}

	public function cleanUp() {
	   Gdn::structure()->table('bThemeSetting')->drop();
	}
}
