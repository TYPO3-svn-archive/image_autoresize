<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Xavier Perseguers (typo3@perseguers.ch)
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*  A copy is found in the textfile GPL.txt and important notices to the license
*  from the author is found in LICENSE.txt distributed with these scripts.
*
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

if (!version_compare(TYPO3_version, '4.4.99', '>')) {
	include_once(t3lib_extMgm::extPath('image_autoresize') . 'interfaces/interface.t3lib_extfilefunctions_processdatahook.php');
}

/**
 * This class extends t3lib_extFileFunctions to automatically resize
 * huge pictures upon upload.
 *
 * @category    Hook
 * @package     TYPO3
 * @subpackage  tx_imageautoresize
 * @author      Xavier Perseguers <typo3@perseguers.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 * @version     SVN: $Id$
 */
class user_fileUpload_hooks implements t3lib_extFileFunctions_processDataHook, t3lib_TCEmain_processUploadHook {

	/**
	 * @var array
	 */
	protected $config;

	/**
	 * Default constructor.
	 */
	public function __construct() {
		$config = $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['image_autoresize_ff'];
		if (!$config) {
			$this->notify(
				$GLOBALS['LANG']->sL('LLL:EXT:image_autoresize/locallang.xml:message.emptyConfiguration'),
				t3lib_FlashMessage::ERROR
			);
		}
		$this->config = unserialize($config);
	}

	/**
	 * Post processes upload of a picture and makes sure it is not too big.
	 *
	 * @param string The uploaded file 
	 * @param t3lib_TCEmain Parent object
	 * @return void
	 */
	public function processUpload_postProcessAction($filename, t3lib_TCEmain $parentObject) {
		$this->processFile($filename);
	}

	/**
	 * Post processes upload of a picture and makes sure it is not too big.
	 *
	 * @param string The action
	 * @param array The parameter sent to the action handler
	 * @param array The results of all calls to the action handler 
	 * @param t3lib_extFileFunctions Parent object
	 * @return void
	 */
	public function processData_postProcessAction($action, array $cmdArr, array $result, t3lib_extFileFunctions $pObj) {
		if ($action !== 'upload') {
				// Early return
			return;
		}

			// Get the latest uploaded file name
		$filename = array_pop($result);
		$this->processFile($filename);
	}

	/**
	 * Processes upload of a file.
	 *
	 * @param string $filename
	 * @return void
	 */
	protected function processFile($filename) {
		$ruleset = $this->getRuleset($filename);

		if (count($ruleset) == 0) {
				// File does not match any rule set
			return;
		}

			// Make filename relative and extract the extension
		$relFilename = substr($filename, strlen(PATH_site));
		$fileExtension = strtolower(substr($filename, strrpos($filename, '.') + 1));

			// Image is bigger than allowed, will now resize it to (hopefully) make it lighter
		$gifCreator = t3lib_div::makeInstance('tslib_gifbuilder');
		$gifCreator->init();
		$gifCreator->absPrefix = PATH_site;

		$hash = t3lib_div::shortMD5($filename);
		$dest = $gifCreator->tempPath . $hash . '.' . $fileExtension;
		$imParams = $ruleset['keep_metadata'] === '1' ? '###SkipStripProfile###' : '';
		$isRotated = FALSE;

		if ($ruleset['auto_orient'] === '1') {
			$imParams .= ' -auto-orient';
			$isRotated = $this->isRotated($filename);
		}

		if ($isRotated) {
				// Invert max_width and max_height as the picture
				// will be automatically rotated
			$options = array(
				'maxW' => $ruleset['max_height'],
				'maxH' => $ruleset['max_width'],
			);
		} else {
			$options = array(
				'maxW' => $ruleset['max_width'],
				'maxH' => $ruleset['max_height'],
			);
		}

		$tempFileInfo = $gifCreator->imageMagickConvert($filename, '', '', '', $imParams, '', $options);
		if ($tempFileInfo) {
				// Replace original file
			@unlink($filename);
			@rename($tempFileInfo[3], $filename);

			$this->notify(
				sprintf(
					$GLOBALS['LANG']->sL('LLL:EXT:image_autoresize/locallang.xml:message.imageResized'),
					$relFilename, $tempFileInfo[0], $tempFileInfo[1]
				),
				t3lib_FlashMessage::INFO
			);
		}
	}

	/**
	 * Returns TRUE if the given picture is rotated.
	 *
	 * @param string $filename
	 * @return boolean
	 * @see http://www.impulseadventure.com/photo/exif-orientation.html
	 */
	protected function isRotated($filename) {
		$ret = FALSE;

		if (function_exists('exif_read_data')) {
			$exif = exif_read_data($filename);
			if ($exif) {
				switch ($exif['Orientation']) {
					case 5: // vertical flip + 90 rotate right
					case 6: // 90 rotate right
					case 7: // horizontal flip + 90 rotate right
					case 8: // 90 rotate left
						$ret = TRUE;
		        		break;
				}	
			}
		}

		return $ret;
	}

	/**
	 * Notifies the user using a Flash message.
	 *
	 * @param string $message The message
	 * @param integer $severity Optional severity, must be either of t3lib_FlashMessage::INFO, t3lib_FlashMessage::OK,
	 *                          t3lib_FlashMessage::WARNING or t3lib_FlashMessage::ERROR. Default is t3lib_FlashMessage::OK.
	 * @return void
	 */
	protected function notify($message, $severity = t3lib_FlashMessage::OK) {
		$flashMessage = t3lib_div::makeInstance(
			't3lib_FlashMessage',
			$message,
			'',
			$severity,
			TRUE
		);
		t3lib_FlashMessageQueue::addMessage($flashMessage);
	}

	/**
	 * Returns the rule set that applies to a given file for
	 * current logged-in backend user.
	 *
	 * @param string $filename
	 * @return array
	 */
	protected function getRuleset($filename) {
		$ret = array();
		if (!is_file($filename)) {
				// Early return
			return $ret;
		}

			// Make filename relative and extract the extension
		$relFilename = substr($filename, strlen(PATH_site));
		$fileExtension = strtolower(substr($filename, strrpos($filename, '.') + 1));

		$beGroups = array_keys($GLOBALS['BE_USER']->userGroups);
		$rulesets = $this->getHookRulesets();

			// Try to find a matching ruleset
		foreach ($rulesets as $ruleset) {
			if (count($ruleset['usergroup']) > 0 && count(array_intersect($ruleset['usergroup'], $beGroups)) == 0) {
					// Backend user is not member of a group configured for the current rule set
				continue;
			}
			$processFile = FALSE;
			foreach ($ruleset['directories'] as $directoryPattern) {
				$processFile |= preg_match($directoryPattern, $relFilename);
			}
			$processFile &= t3lib_div::inArray($ruleset['file_types'], $fileExtension);
			$processFile &= (filesize($filename) > $ruleset['threshold']);
			if ($processFile) {
				$ret = $ruleset;
				break;
			}
		}
		return $ret;
	}

	/**
	 * Returns the hook configuration as a meaningful ordered list
	 * of rule sets.
	 *
	 * @return array
	 */
	protected function getHookRulesets() {
		$general = $this->config;
		$general['usergroup'] = '';
		unset($general['rulesets']);
		$general = $this->expandValuesInRuleset($general);
		if (isset($this->config['rulesets'])) {
			$rulesets = $this->compileRuleSets($this->config['rulesets']);
		} else {
			$rulesets = array();
		}

			// Inherit values from general configuration in rule sets if needed
		foreach ($rulesets as $k => &$ruleset) {
			foreach ($general as $key => $value) {
				if (!isset($ruleset[$key])) {
					$ruleset[$key] = $value;
				} elseif ($ruleset[$key] === '') {
					$ruleset[$key] = $value;
				}
			}
			if (count($ruleset['usergroup']) == 0) {
					// Make sure not to try to override general configuration
					// => only keep directories not present in general configuration
				$ruleset['directories'] = array_diff($ruleset['directories'], $general['directories']);
				if (count($ruleset['directories']) == 0) {
					unset($rulesets[$k]);
				}
			}
		}

			// Use general configuration as very first rule set
		array_unshift($rulesets, $general); 
		return $rulesets;
	}

	/**
	 * Compiles all rule sets.
	 *
	 * @param array $rulesets
	 * @return array
	 */
	protected function compileRulesets(array $rulesets) {
		$sheets = t3lib_div::resolveAllSheetsInDS($rulesets);
		$rulesets = array();

		foreach ($sheets['sheets'] as $sheet) {
			$elements = $sheet['data']['sDEF']['lDEF']['ruleset']['el'];
			foreach ($elements as $container) {
				if (isset($container['container']['el'])) {
					$values = array();
					foreach ($container['container']['el'] as $key => $value) {
						if ($key === 'title') {
							continue;
						}
						$values[$key] = $value['vDEF'];
					}
					$rulesets[] = $this->expandValuesInRuleset($values);
				}
			}
		}

		return $rulesets;
	}

	/**
	 * Expands values of a rule set.
	 *
	 * @param array $ruleset
	 * @return array
	 */
	protected function expandValuesInRuleset(array $ruleset) {
		$values = array();
		foreach ($ruleset as $key => $value) {
			switch ($key) {
				case 'usergroup':
					$value = t3lib_div::trimExplode(',', $value, TRUE);
					break;
				case 'directories':
					$value = t3lib_div::trimExplode(',', $value, TRUE);
						// Sanitize name of the directories
					foreach ($value as &$directory) {
						$directory = rtrim($directory, '/') . '/';
						$directory = $this->getDirectoryPattern($directory);
					}
					if (count($value) == 0) {
							// Inherit configuration
						$value = '';
					}
					break;
				case 'file_types':
					$value = t3lib_div::trimExplode(',', $value, TRUE);
					if (count($value) == 0) {
							// Inherit configuration
						$value = '';
					}
					break;
				case 'threshold':
					if (!is_numeric($value)) {
						$unit = strtoupper(substr($value, -1));
						$factor = 1 * ($unit === 'K' ? 1024 : ($unit === 'M' ? 1024 * 1024 : 0));
						$value = intval(trim(substr($value, 0, strlen($value) - 1))) * $factor;
					}
					// Beware: fall-back to next value processing
				case 'max_width':
				case 'max_height':
					if ($value <= 0) {
							// Inherit configuration
						$value = '';
					}
					break;
			}
			$values[$key] = $value;
		}

		return $values;
	}

	/**
	 * Returns a regular expression pattern to match directories.
	 *
	 * @param string $directory
	 * @return string
	 */
	protected function getDirectoryPattern($directory) {
        $pattern = '/^' . str_replace('/', '\\/', $directory) . '/';
        $pattern = str_replace('\\/**\\/', '\\/([^\/]+\\/)*', $pattern);
        $pattern = str_replace('\\/*\\/', '\\/[^\/]+\\/', $pattern);

        return $pattern;
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/image_autoresize/classes/class.user_t3lib_extfilefunctions_hook.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/image_autoresize/classes/class.user_t3lib_extfilefunctions_hook.php']);
}
?>