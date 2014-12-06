<?php

	/** Define the template paths for backwards compatibility. */
	if (!defined ('__TEMPLATES_PATH_CORE__')) define ('__TEMPLATES_PATH_CORE__', __QCUBED_CORE__ . '/codegen/templates/');
	if (!defined ('__TEMPLATES_PATH_PLUGIN__')) define ('__TEMPLATES_PATH_PLUGIN__', '');
	if (!defined ('__TEMPLATES_PATH_PROJECT__')) define ('__TEMPLATES_PATH_PROJECT__', __QCUBED__ . '/codegen/templates/');


	function QcodoHandleCodeGenParseError($__exc_errno, $__exc_errstr, $__exc_errfile, $__exc_errline) {
		$strErrorString = str_replace("SimpleXMLElement::__construct() [<a href='function.SimpleXMLElement---construct'>function.SimpleXMLElement---construct</a>]: ", '', $__exc_errstr);
		QCodeGen::$RootErrors .= sprintf("%s\r\n", $strErrorString);
	}

	function GO_BACK($intNumChars) {
		$content_so_far = ob_get_contents();
		ob_end_clean();
		$content_so_far = substr($content_so_far, 0, strlen($content_so_far) - $intNumChars);
		ob_start();
		print $content_so_far;
	}

	// returns true if $str begins with $sub
	function beginsWith( $str, $sub ) {
	    return ( substr( $str, 0, strlen( $sub ) ) == $sub );
	}

	// return tru if $str ends with $sub
	function endsWith( $str, $sub ) {
	    return ( substr( $str, strlen( $str ) - strlen( $sub ) ) == $sub );
	}

	// trims off x chars from the front of a string
	// or the matching string in $off is trimmed off
	function trimOffFront( $off, $str ) {
	    if( is_numeric( $off ) )
	        return substr( $str, $off );
	    else
	        return substr( $str, strlen( $off ) );
	}

	// trims off x chars from the end of a string
	// or the matching string in $off is trimmed off
	function trimOffEnd( $off, $str ) {
	    if( is_numeric( $off ) )
	        return substr( $str, 0, strlen( $str ) - $off );
	    else
	        return substr( $str, 0, strlen( $str ) - strlen( $off ) );
	}

	/**
	 * This is the CodeGen class which performs the code generation
	 * for both the Object-Relational Model (e.g. Data Objects) as well as
	 * the draft Forms, which make up simple HTML/PHP scripts to perform
	 * basic CRUD functionality on each object.
	 * @package Codegen
	 */
	abstract class QCodeGenBase extends QBaseClass {
		// Class Name Suffix/Prefix
		/** @var string Class Prefix, as specified in the codegen_settings.xml file */
		protected $strClassPrefix;
		/** @var string Class suffix, as specified in the codegen_settings.xml file */
		protected $strClassSuffix;

		/** string Errors and Warnings collected during the process of codegen **/
		protected $strErrors;

		/**
		 * PHP Reserved Words.  They make up:
		 * Invalid Type names -- these are reserved words which cannot be Type names in any user type table
		 * Invalid Table names -- these are reserved words which cannot be used as any table name
		 * Please refer to : http://php.net/manual/en/reserved.php
		 */
		const PhpReservedWords = 'new, null, break, return, switch, self, case, const, clone, continue, declare, default, echo, else, elseif, empty, exit, eval, if, try, throw, catch, public, private, protected, function, extends, foreach, for, while, do, var, class, static, abstract, isset, unset, implements, interface, instanceof, include, include_once, require, require_once, abstract, and, or, xor, array, list, false, true, global, parent, print, exception, namespace, goto, final, endif, endswitch, enddeclare, endwhile, use, as, endfor, endforeach, this';

		/** Core templates path */
		const TemplatesPathCore = __TEMPLATES_PATH_CORE__;
		/** Plugins templates path */
		const TemplatesPathPlugin = __TEMPLATES_PATH_PLUGIN__;
		const TemplatesPathProject = __TEMPLATES_PATH_PROJECT__;

		/**
		 * DebugMode -- for Template Developers
		 * This will output the current evaluated template/statement to the screen
		 * On "eval" errors, you can click on the "View Rendered Page" to see what currently
		 * is being evaluated, which should hopefully aid in template debugging.
		 */
		const DebugMode = false;

		/**
		 * This static array contains an array of active and executed codegen objects, based
		 * on the XML Configuration passed in to Run()
		 *
		 * @var QCodeGen[] array of active/executed codegen objects
		 */
		public static $CodeGenArray;

		/**
		 * This is the array representation of the parsed SettingsXml
		 * for reportback purposes.
		 *
		 * @var string[] array of config settings
		 */
		protected static $SettingsXmlArray;

		/**
		 * This is the SimpleXML representation of the Settings XML file
		 *
		 * @var SimpleXmlElement the XML representation
		 */
		protected static $SettingsXml;

		public static $SettingsFilePath;

		/**
		 * Application Name (from CodeGen Settings)
		 *
		 * @var string $ApplicationName
		 */
		protected static $ApplicationName;

		/**
		 * Template Escape Begin (from CodeGen Settings)
		 *
		 * @var string $TemplateEscapeBegin
		 */
		protected static $TemplateEscapeBegin;
		protected static $TemplateEscapeBeginLength;

		/**
		 * Template Escape End (from CodeGen Settings)
		 *
		 * @var string $TemplateEscapeEnd
		 */
		protected static $TemplateEscapeEnd;
		protected static $TemplateEscapeEndLength;

		public static $RootErrors = '';

		/**
		 * @var string[] array of directories to be excluded in codegen (lower cased)
		 * @access protected
		 */
		protected static $DirectoriesToExcludeArray = array('.','..','.svn','svn','cvs','.git');

		/**
		 * Gets the settings in codegen_settings.xml file and returns its text without comments
		 * @return string
		 */
		public static function GetSettingsXml() {
			$strCrLf = "\r\n";

			$strToReturn = sprintf('<codegen>%s', $strCrLf);
			$strToReturn .= sprintf('	<name application="%s"/>%s', QCodeGen::$ApplicationName, $strCrLf);
			$strToReturn .= sprintf('	<templateEscape begin="%s" end="%s"/>%s', QCodeGen::$TemplateEscapeBegin, QCodeGen::$TemplateEscapeEnd, $strCrLf);
			$strToReturn .= sprintf('	<dataSources>%s', $strCrLf);
			foreach (QCodeGen::$CodeGenArray as $objCodeGen)
				$strToReturn .= $strCrLf . $objCodeGen->GetConfigXml();
			$strToReturn .= sprintf('%s	</dataSources>%s', $strCrLf, $strCrLf);
			$strToReturn .= '</codegen>';

			return $strToReturn;
		}

		/**
		 * The function which actually performs the steps for code generation
		 * Code generation begins here.
		 * @param string $strSettingsXmlFilePath Path to the settings file
		 */
		public static function Run($strSettingsXmlFilePath) {
			QCodeGen::$CodeGenArray = array();
			QCodeGen::$SettingsFilePath = $strSettingsXmlFilePath;

			if (!file_exists($strSettingsXmlFilePath)) {
				QCodeGen::$RootErrors = 'FATAL ERROR: CodeGen Settings XML File (' . $strSettingsXmlFilePath . ') was not found.';
				return;
			}

			if (!is_file($strSettingsXmlFilePath)) {
				QCodeGen::$RootErrors = 'FATAL ERROR: CodeGen Settings XML File (' . $strSettingsXmlFilePath . ') was not found.';
				return;
			}

			// Try Parsing the Xml Settings File
			try {
				QApplication::SetErrorHandler('QcodoHandleCodeGenParseError', E_ALL);
				QCodeGen::$SettingsXml = new SimpleXMLElement(file_get_contents($strSettingsXmlFilePath));
				QApplication::RestoreErrorHandler();
			} catch (Exception $objExc) {
				QCodeGen::$RootErrors .= 'FATAL ERROR: Unable to parse CodeGenSettings XML File: ' . $strSettingsXmlFilePath;
				QCodeGen::$RootErrors .= "\r\n";
				QCodeGen::$RootErrors .= $objExc->getMessage();
				return;
			}

			// Set the Template Escaping
			QCodeGen::$TemplateEscapeBegin = QCodeGen::LookupSetting(QCodeGen::$SettingsXml, 'templateEscape', 'begin');
			QCodeGen::$TemplateEscapeEnd = QCodeGen::LookupSetting(QCodeGen::$SettingsXml, 'templateEscape', 'end');
			QCodeGen::$TemplateEscapeBeginLength = strlen(QCodeGen::$TemplateEscapeBegin);
			QCodeGen::$TemplateEscapeEndLength = strlen(QCodeGen::$TemplateEscapeEnd);

			if ((!QCodeGen::$TemplateEscapeBeginLength) || (!QCodeGen::$TemplateEscapeEndLength)) {
				QCodeGen::$RootErrors .= "CodeGen Settings XML Fatal Error: templateEscape begin and/or end was not defined\r\n";
				return;
			}

			// Application Name
			QCodeGen::$ApplicationName = QCodeGen::LookupSetting(QCodeGen::$SettingsXml, 'name', 'application');

			// Iterate Through DataSources
			if (QCodeGen::$SettingsXml->dataSources->asXML())
				foreach (QCodeGen::$SettingsXml->dataSources->children() as $objChildNode) {
					switch (dom_import_simplexml($objChildNode)->nodeName) {
						case 'database':
							QCodeGen::$CodeGenArray[] = new QDatabaseCodeGen($objChildNode);
							break;
						case 'restService':
							QCodeGen::$CodeGenArray[] = new QRestServiceCodeGen($objChildNode);
							break;
						default:
							QCodeGen::$RootErrors .= sprintf("Invalid Data Source Type in CodeGen Settings XML File (%s): %s\r\n",
								$strSettingsXmlFilePath, dom_import_simplexml($objChildNode)->nodeName);
							break;
					}
				}
		}

		/**
		 * This will lookup either the node value (if no attributename is passed in) or the attribute value
		 * for a given Tag.  Node Searches only apply from the root level of the configuration XML being passed in
		 * (e.g. it will not be able to lookup the tag name of a grandchild of the root node)
		 *
		 * If No Tag Name is passed in, then attribute/value lookup is based on the root node, itself.
		 *
		 * @param SimpleXmlElement $objNode
		 * @param string $strTagName
		 * @param string $strAttributeName
		 * @param string $strType
		 * @return mixed the return type depends on the QType you pass in to $strType
		 */
		static protected function LookupSetting($objNode, $strTagName, $strAttributeName = null, $strType = QType::String) {
			if ($strTagName)
				$objNode = $objNode->$strTagName;

			if ($strAttributeName) {
				switch ($strType) {
					case QType::Integer:
						try {
							$intToReturn = QType::Cast($objNode[$strAttributeName], QType::Integer);
							return $intToReturn;
						} catch (Exception $objExc) {
							return null;
						}
					case QType::Boolean:
						try {
							$blnToReturn = QType::Cast($objNode[$strAttributeName], QType::Boolean);
							return $blnToReturn;
						} catch (Exception $objExc) {
							return null;
						}
					default:
						$strToReturn = trim(QType::Cast($objNode[$strAttributeName], QType::String));
						return $strToReturn;
				}
			} else {
				$strToReturn = trim(QType::Cast($objNode, QType::String));
				return $strToReturn;
			}
		}

		/**
		 *
		 * @return array
		 */
		public static function GenerateAggregate() {
			$objDbOrmCodeGen = array();
			$objRestServiceCodeGen = array();

			foreach (QCodeGen::$CodeGenArray as $objCodeGen) {
				if ($objCodeGen instanceof QDatabaseCodeGen)
					array_push($objDbOrmCodeGen, $objCodeGen);
				if ($objCodeGen instanceof QRestServiceCodeGen)
					array_push($objRestServiceCodeGen, $objCodeGen);
			}

			$strToReturn = array();
			array_merge($strToReturn, QDatabaseCodeGen::GenerateAggregateHelper($objDbOrmCodeGen));
//			array_push($strToReturn, QRestServiceCodeGen::GenerateAggregateHelper($objRestServiceCodeGen));

			return $strToReturn;
		}

		/**
		 * Given a template prefix (e.g. db_orm_, db_type_, rest_, soap_, etc.), pull
		 * all the _*.tpl templates from any subfolders of the template prefix
		 * in QCodeGen::TemplatesPath and QCodeGen::TemplatesPathCustom,
		 * and call GenerateFile() on each one.  If there are any template files that reside
		 * in BOTH TemplatesPath AND TemplatesPathCustom, then only use the TemplatesPathCustom one (which
		 * in essence overrides the one in TemplatesPath)
		 *
		 * @param string  $strTemplatePrefix the prefix of the templates you want to generate against
		 * @param mixed[] $mixArgumentArray  array of arguments to send to EvaluateTemplate
		 *
		 * @throws Exception
		 * @throws QCallerException
		 * @return boolean success/failure on whether or not all the files generated successfully
		 */
		public function GenerateFiles($strTemplatePrefix, $mixArgumentArray) {
			// If you are editing core templates, and getting EOF errors only on the travis build, this may be your problem. Scan your files and remove short tags.
			if (QCodeGen::DebugMode && ini_get ('short_open_tag')) _p("Warning: PHP directive short_open_tag is on. Using short tags will cause unexpected EOF on travis build.\n", false);

			$strTemplatePathCore = sprintf('%s%s', QCodeGen::TemplatesPathCore, $strTemplatePrefix);
			if (!is_dir($strTemplatePathCore))
				throw new Exception(sprintf("__TEMPLATES_PATH_CORE__ does not appear to be a valid directory:\r\n%s", $strTemplatePathCore));

			$strTemplatePathPlugin = '';
			if (QCodeGen::TemplatesPathPlugin) {
				$strTemplatePathPlugin = QCodeGen::TemplatesPathCustom;
				if (!is_dir($strTemplatePathPlugin))
					throw new Exception(sprintf("__TEMPLATES_PATH_PLUGIN__ does not appear to be a valid directory:\r\n%s", $strTemplatePathPlugin));
				$strTemplatePathPlugin .= $strTemplatePrefix;
			}

			$strTemplatePathProject = QCodeGen::TemplatesPathProject;
			if (!is_dir($strTemplatePathProject))
				throw new Exception(sprintf("__TEMPLATES_PATH_PROJECT__ does not appear to be a valid directory:\r\n%s", $strTemplatePathProject));
			$strTemplatePathProject .= $strTemplatePrefix;

			// Create an array of arrays of standard templates and custom (override) templates to process
			// Index by [module_name][filename] => true/false where
			// module name (e.g. "class_gen", "form_delegates) is name of folder within the prefix (e.g. "db_orm")
			// filename is the template filename itself (in a _*.tpl format)
			// true = override (use custom) and false = do not override (use standard)
			$strTemplateArray = array();

			// Go through standard templates first, then override in order
			$this->buildTemplateArray($strTemplatePathCore, $strTemplateArray);
			$this->buildTemplateArray($strTemplatePathPlugin, $strTemplateArray);
			$this->buildTemplateArray($strTemplatePathProject, $strTemplateArray);

			// Finally, iterate through all the TemplateFiles and call GenerateFile to Evaluate/Generate/Save them
			$blnSuccess = true;
			foreach ($strTemplateArray as $strModuleName => $strFileArray) {
				foreach ($strFileArray as $strFilename => $strPath) {
					if (!$this->GenerateFile($strModuleName, $strPath, $mixArgumentArray)) {
						$blnSuccess = false;
					}
				}
			}

			return $blnSuccess;
		}

		protected function buildTemplateArray ($strTemplateFilePath, &$strTemplateArray) {
			if (!$strTemplateFilePath) return;
			if (substr( $strTemplateFilePath, -1 ) != '/') {
				$strTemplateFilePath .= '/';
			}
			if (is_dir($strTemplateFilePath)) {
				$objDirectory = opendir($strTemplateFilePath);
				while ($strModuleName = readdir($objDirectory)) {
					if (!in_array(strtolower($strModuleName), QCodeGen::$DirectoriesToExcludeArray) &&
							is_dir($strTemplateFilePath . $strModuleName)) {
						$objModuleDirectory = opendir($strTemplateFilePath . $strModuleName);
						while ($strFilename = readdir($objModuleDirectory)) {
							if ((QString::FirstCharacter($strFilename) == '_') &&
								(substr($strFilename, strlen($strFilename) - 8) == '.tpl.php')
							) {
								$strTemplateArray[$strModuleName][$strFilename] = $strTemplateFilePath . $strModuleName . '/' . $strFilename;
							}
						}
					}
				}
			}
		}

		/**
		 * Returns the settings of the template file as SimpleXMLElement object
		 *
		 * @param null|string $strTemplateFilePath Path to the file
		 * @param null|string $strTemplate         Text of the template (if $strTemplateFilePath is null, this field must be string)
		 *
		 * @return SimpleXMLElement
		 * @throws Exception
		 */
		protected function getTemplateSettings($strTemplateFilePath, $strTemplate = null) {
			if ($strTemplate === null)
				$strTemplate = file_get_contents($strTemplateFilePath);
			$strError = 'Template\'s first line must be <template OverwriteFlag="boolean" DocrootFlag="boolean" TargetDirectory="string" DirectorySuffix="string" TargetFileName="string"/>: ' . $strTemplateFilePath;
			// Parse out the first line (which contains path and overwriting information)
			$intPosition = strpos($strTemplate, "\n");
			if ($intPosition === false) {
				throw new Exception($strError);
			}

			$strFirstLine = trim(substr($strTemplate, 0, $intPosition));

			$objTemplateXml = null;
			// Attempt to Parse the First Line as XML
			try {
				@$objTemplateXml = new SimpleXMLElement($strFirstLine);
			} catch (Exception $objExc) {}

			if (is_null($objTemplateXml) || (!($objTemplateXml instanceof SimpleXMLElement)))
				throw new Exception($strError);
			return $objTemplateXml;
		}

		/**
		 * Generates a php code using a template file
		 *
		 * @param string  $strModuleName
		 * @param string  $strTemplateFilePath Path to the template file
		 * @param mixed[] $mixArgumentArray
		 * @param boolean $blnSave             whether or not to actually perform the save
		 *
		 * @throws QCallerException
		 * @throws Exception
		 * @return mixed returns the evaluated template or boolean save success.
		 */
		public function GenerateFile($strModuleName, $strTemplateFilePath, $mixArgumentArray, $blnSave = true) {
			// Setup Debug/Exception Message
			if (QCodeGen::DebugMode) _p("Evaluating $strTemplateFilePath<br/>", false);

			// Check to see if the template file exists, and if it does, Load It
			if (!file_exists($strTemplateFilePath))
				throw new QCallerException('Template File Not Found: ' . $strTemplateFilePath);

			// Evaluate the Template
			// make sure paths are set up to pick up included files from the various directories
			$a[] = QCodeGen::TemplatesPathCore . $strModuleName;
			if (QCodeGen::TemplatesPathPlugin) {
				array_unshift ($a, QCodeGen::TemplatesPathPlugin . $strModuleName);
			}
			if (QCodeGen::TemplatesPathProject) {
				array_unshift ($a, QCodeGen::TemplatesPathProject . $strModuleName);
			}
			$strSearchPath = implode (PATH_SEPARATOR, $a) . PATH_SEPARATOR . get_include_path();
			$strOldIncludePath = set_include_path ($strSearchPath);
			if ($strSearchPath != get_include_path()) {
				throw new QCallerException ('Can\'t override include path. Make sure your apache or server settings allow include paths to be overridden. ' );
			}

			$strTemplate = $this->EvaluatePHP($strTemplateFilePath, $strModuleName, $mixArgumentArray, $templateSettings);
			set_include_path($strOldIncludePath);

			$blnOverwriteFlag = QType::Cast($templateSettings['OverwriteFlag'], QType::Boolean);
			$blnDocrootFlag = QType::Cast($templateSettings['DocrootFlag'], QType::Boolean);
			$strTargetDirectory = QType::Cast($templateSettings['TargetDirectory'], QType::String);
			$strDirectorySuffix = QType::Cast($templateSettings['DirectorySuffix'], QType::String);
			$strTargetFileName = QType::Cast($templateSettings['TargetFileName'], QType::String);

			if (is_null($blnOverwriteFlag) || is_null($strTargetFileName) || is_null($strTargetDirectory) || is_null($strDirectorySuffix) || is_null($blnDocrootFlag))
				throw new Exception('the template settings cannot be null');

			if ($blnSave && $strTargetDirectory) {
				// Figure out the REAL target directory
				if ($blnDocrootFlag)
					$strTargetDirectory = __DOCROOT__ . $strTargetDirectory . $strDirectorySuffix;
				else
					$strTargetDirectory = $strTargetDirectory . $strDirectorySuffix;

				// Create Directory (if needed)
				if (!is_dir($strTargetDirectory))
					if (!QApplication::MakeDirectory($strTargetDirectory, 0777))
						throw new Exception('Unable to mkdir ' . $strTargetDirectory);

				// Save to Disk
				$strFilePath = sprintf('%s/%s', $strTargetDirectory, $strTargetFileName);
				if ($blnOverwriteFlag || (!file_exists($strFilePath))) {
					$intBytesSaved = file_put_contents($strFilePath, $strTemplate);

					$this->setGeneratedFilePermissions($strFilePath);
					return ($intBytesSaved == strlen($strTemplate));
				} else
					// Becuase we are not supposed to overwrite, we should return "true" by default
					return true;
			}

			// Why Did We Not Save?
			if ($blnSave) {
				// We WANT to Save, but QCubed Configuration says that this functionality/feature should no longer be generated
				// By definition, we should return "true"
				return true;
			}
			// Running GenerateFile() specifically asking it not to save -- so return the evaluated template instead
			return $strTemplate;
		}

		/**
		 * Sets the file permissions (Linux only) for a file generated by the Code Generator
		 * @param $strFilePath Path of the generated file
		 *
		 * @throws QCallerException
		 */
		protected function setGeneratedFilePermissions($strFilePath) {
			// CHMOD to full read/write permissions (applicable only to nonwindows)
			// Need to ignore error handling for this call just in case
			if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
				QApplication::SetErrorHandler(null);
				chmod($strFilePath, 0666);
				QApplication::RestoreErrorHandler();
			}
		}

		protected function EvaluatePHP($strFilename, $strModuleName, $mixArgumentArray, &$templateSettings = null)  {
			// Get all the arguments and set them locally
			if ($mixArgumentArray) foreach ($mixArgumentArray as $strName=>$mixValue) {
				$$strName = $mixValue;
			}
			global $_TEMPLATE_SETTINGS;
			unset($_TEMPLATE_SETTINGS);
			$_TEMPLATE_SETTINGS = null;

			// Of course, we also need to locally allow "objCodeGen"
			$objCodeGen = $this;

			// Get Database Escape Identifiers
			$strEscapeIdentifierBegin = QApplication::$Database[$this->intDatabaseIndex]->EscapeIdentifierBegin;
			$strEscapeIdentifierEnd = QApplication::$Database[$this->intDatabaseIndex]->EscapeIdentifierEnd;

			// Store the Output Buffer locally
			$strAlreadyRendered = ob_get_contents();

			if (ob_get_level()) ob_clean();
			ob_start();
			include($strFilename);
			$strTemplate = ob_get_contents();
			ob_end_clean();

			$templateSettings = $_TEMPLATE_SETTINGS;
			unset($_TEMPLATE_SETTINGS);

			// Restore the output buffer and return evaluated template
			print($strAlreadyRendered);

			// Remove all \r from the template (for Win/*nix compatibility)
			$strTemplate = str_replace("\r", '', $strTemplate);
			return $strTemplate;
		}

		///////////////////////
		// COMMONLY OVERRIDDEN CONVERSION FUNCTIONS
		///////////////////////

		/**
		 * Given name of a table, will return the class name (according to the settings in codegen_settings.xml)
		 * @param string $strTableName Name of the table
		 *
		 * @return string
		 */
		protected function ClassNameFromTableName($strTableName) {
			$strTableName = $this->StripPrefixFromTable($strTableName);
			return sprintf('%s%s%s',
				$this->strClassPrefix,
				QConvertNotation::CamelCaseFromUnderscore($strTableName),
				$this->strClassSuffix);
		}

		/**
		 * Given a column name of a table, it will return the variable name for a column
		 * @param QColumn $objColumn
		 *
		 * @return string
		 */
		protected function VariableNameFromColumn(QColumn $objColumn) {
			return QConvertNotation::PrefixFromType($objColumn->VariableType) .
				QConvertNotation::CamelCaseFromUnderscore($objColumn->Name);
		}

		/**
		 * Returns the label name for the meta control. Can be overridden in the comment for the column.
		 *
		 * @param QColumn $objColumn
		 *
		 * @internal param string $strDelimiter
		 * @return string
		 */
		public static function MetaControlLabelNameFromColumn (QColumn $objColumn) {
			if (($o = $objColumn->Options) && isset ($o['Name'])) {
				return $o['Name'];
			}
			if ($objColumn->Reference) {
				return QConvertNotation::WordsFromCamelCase($objColumn->Reference->PropertyName);
			}
            return QConvertNotation::WordsFromCamelCase($objColumn->PropertyName);
		}

		protected function PropertyNameFromColumn(QColumn $objColumn) {
			return QConvertNotation::CamelCaseFromUnderscore($objColumn->Name);
		}

		protected function TypeNameFromColumnName($strName) {
			return QConvertNotation::CamelCaseFromUnderscore($strName);
		}

		protected function ReferenceColumnNameFromColumn(QColumn $objColumn) {
			$strColumnName = $objColumn->Name;
			$intNameLength = strlen($strColumnName);

			// Does the column name for this reference column end in "_id"?
			if (($intNameLength > 3) && (substr($strColumnName, $intNameLength - 3) == "_id")) {
				// It ends in "_id" but we don't want to include the "Id" suffix
				// in the Variable Name.  So remove it.
				$strColumnName = substr($strColumnName, 0, $intNameLength - 3);
			} else {
				// Otherwise, let's add "_object" so that we don't confuse this variable name
				// from the variable that was mapped from the physical database
				// E.g., if it's a numeric FK, and the column is defined as "person INT",
				// there will end up being two variables, one for the Person id integer, and
				// one for the Person object itself.  We'll add Object t o the name of the Person object
				// to make this deliniation.
				$strColumnName = sprintf("%s_object", $strColumnName);
			}

			return $strColumnName;
		}

		protected function ReferenceVariableNameFromColumn(QColumn $objColumn) {
			$strColumnName = $this->ReferenceColumnNameFromColumn($objColumn);
			return QConvertNotation::PrefixFromType(QType::Object) .
				QConvertNotation::CamelCaseFromUnderscore($strColumnName);
		}

		protected function ReferencePropertyNameFromColumn(QColumn $objColumn) {
			$strColumnName = $this->ReferenceColumnNameFromColumn($objColumn);
			return QConvertNotation::CamelCaseFromUnderscore($strColumnName);
		}

		public function VariableNameFromTable($strTableName) {
			$strTableName = $this->StripPrefixFromTable($strTableName);
			return QConvertNotation::PrefixFromType(QType::Object) .
				QConvertNotation::CamelCaseFromUnderscore($strTableName);
		}

		protected function ReverseReferenceVariableNameFromTable($strTableName) {
			$strTableName = $this->StripPrefixFromTable($strTableName);
			return $this->VariableNameFromTable($strTableName);
		}

		protected function ReverseReferenceVariableTypeFromTable($strTableName) {
			$strTableName = $this->StripPrefixFromTable($strTableName);
			return $this->ClassNameFromTableName($strTableName);
		}

		protected function ParameterCleanupFromColumn(QColumn $objColumn, $blnIncludeEquality = false) {
			if ($blnIncludeEquality)
				return sprintf('$%s = $objDatabase->SqlVariable($%s, true);',
					$objColumn->VariableName, $objColumn->VariableName);
			else
				return sprintf('$%s = $objDatabase->SqlVariable($%s);',
					$objColumn->VariableName, $objColumn->VariableName);
		}

		// To be used to list the columns as input parameters, or as parameters for sprintf
		protected function ParameterListFromColumnArray($objColumnArray) {
			return $this->ImplodeObjectArray(', ', '$', '', 'VariableName', $objColumnArray);
		}

		protected function ImplodeObjectArray($strGlue, $strPrefix, $strSuffix, $strProperty, $objArrayToImplode) {
			$strArrayToReturn = array();
			if ($objArrayToImplode) foreach ($objArrayToImplode as $objObject) {
				array_push($strArrayToReturn, sprintf('%s%s%s', $strPrefix, $objObject->__get($strProperty), $strSuffix));
			}

			return implode($strGlue, $strArrayToReturn);
		}

		protected function TypeTokenFromTypeName($strName) {
			$strToReturn = '';
			for($intIndex = 0; $intIndex < strlen($strName); $intIndex++)
				if (((ord($strName[$intIndex]) >= ord('a')) &&
					 (ord($strName[$intIndex]) <= ord('z'))) ||
					((ord($strName[$intIndex]) >= ord('A')) &&
					 (ord($strName[$intIndex]) <= ord('Z'))) ||
					((ord($strName[$intIndex]) >= ord('0')) &&
					 (ord($strName[$intIndex]) <= ord('9'))) ||
					($strName[$intIndex] == '_'))
					$strToReturn .= $strName[$intIndex];

			if (is_numeric(QString::FirstCharacter($strToReturn)))
				$strToReturn = '_' . $strToReturn;
			return $strToReturn;
		}

		public function FormControlVariableNameForColumn(QColumn $objColumn) {
			if ($objColumn->Reference) {
				$strPropName = $objColumn->Reference->PropertyName;
			} else {
				$strPropName = $objColumn->PropertyName;
			}

			$strClassName = $this->FormControlClassForColumn($objColumn);

			return $strClassName::Codegen_VarName ($strPropName);
		}

		/**
		 * This function returns the data type for table column
		 * NOTE: The data type is not the PHP data type, but classes used by QCubed
		 * @param QColumn $objColumn
		 *
		 * @return string
		 */

		public function FormControlClassForColumn(QColumn $objColumn) {
			if (($o = $objColumn->Options) && isset($o['ControlClass'])) {
				return $o['ControlClass'];
			}

			if ($objColumn->Identity)
				return 'QLabel';

			if ($objColumn->Timestamp)
				return 'QLabel';

			if ($objColumn->Reference)
				return 'QListBox';

			switch ($objColumn->VariableType) {
				case QType::Boolean:
					return 'QCheckBox';
				case QType::DateTime:
					return 'QDateTimePicker';
				case QType::Integer:
					return 'QIntegerTextBox';
				case QType::Float:
					return 'QFloatTextBox';
				default:
					return 'QTextBox';
			}
		}

		protected function FormControlVariableNameForUniqueReverseReference(QReverseReference $objReverseReference) {
			if ($objReverseReference->Unique) {
				return sprintf("lst%s", $objReverseReference->ObjectDescription);
			} else
				throw new Exception('FormControlVariableNameForUniqueReverseReference requires ReverseReference to be unique');
		}

		protected function FormControlVariableNameForManyToManyReference(QManyToManyReference $objManyToManyReference) {
			if ($objManyToManyReference->IsTypeAssociation) {
				$strPre = 'lst%s';
			} else {
				$strPre = 'dtg%s';
			}
			return sprintf($strPre, $objManyToManyReference->ObjectDescriptionPlural);
		}

		public function FormLabelVariableNameForColumn(QColumn $objColumn) {
			if ($objColumn->Reference) {
				$strPropName = $objColumn->Reference->PropertyName;
			} else {
				$strPropName = $objColumn->PropertyName;
			}
			return QLabel::Codegen_VarName($strPropName);
		}

		protected function FormLabelVariableNameForUniqueReverseReference(QReverseReference $objReverseReference) {
			if ($objReverseReference->Unique) {
				return sprintf("lbl%s", $objReverseReference->ObjectDescription);
			} else
				throw new Exception('FormControlVariableNameForUniqueReverseReference requires ReverseReference to be unique');
		}

		protected function FormLabelVariableNameForManyToManyReference(QManyToManyReference $objManyToManyReference) {
			return sprintf("lbl%s", $objManyToManyReference->ObjectDescriptionPlural);
		}

		/**
		 * Given a column of a table, returns the name of QControl class which should be used to input data
		 * into the table. (QLable for Identity columns)
		 *
		 * @param QColumn $objColumn
		 *
		 * @throws Exception
		 */
		protected function FormControlTypeForColumn(QColumn $objColumn) {
			if ($objColumn->Identity)
				return 'QLabel';

			if ($objColumn->Timestamp)
				return 'QLabel';

			if ($objColumn->Reference)
				return 'QListBox';

			switch ($objColumn->VariableType) {
				case QType::Boolean:
					return 'QCheckBox';
				case QType::DateTime:
					return 'QCalendar';
				case QType::Float:
					return 'QFloatTextBox';
				case QType::Integer:
					return 'QIntegerTextBox';
				case QType::String:
					return 'QTextBox';
				default:
					throw new Exception('Unknown type for Column: %s' . $objColumn->VariableType);
			}
		}

		protected function CalculateObjectMemberVariable($strTableName, $strColumnName, $strReferencedTableName) {
			return sprintf('%s%s%s%s',
				QConvertNotation::PrefixFromType(QType::Object),
				$this->strAssociatedObjectPrefix,
				$this->CalculateObjectDescription($strTableName, $strColumnName, $strReferencedTableName, false),
				$this->strAssociatedObjectSuffix);
		}

		protected function CalculateObjectPropertyName($strTableName, $strColumnName, $strReferencedTableName) {
			return sprintf('%s%s%s',
				$this->strAssociatedObjectPrefix,
				$this->CalculateObjectDescription($strTableName, $strColumnName, $strReferencedTableName, false),
				$this->strAssociatedObjectSuffix);
		}

		// TODO: These functions need to be documented heavily with information from "lexical analysis on fk names.txt"
		protected function CalculateObjectDescription($strTableName, $strColumnName, $strReferencedTableName, $blnPluralize) {
			// Strip Prefixes (if applicable)
			$strTableName = $this->StripPrefixFromTable($strTableName);
			$strReferencedTableName = $this->StripPrefixFromTable($strReferencedTableName);

			// Starting Point
			$strToReturn = QConvertNotation::CamelCaseFromUnderscore($strTableName);

			if ($blnPluralize)
				$strToReturn = $this->Pluralize($strToReturn);

			if ($strTableName == $strReferencedTableName) {
				// Self-referencing Reference to Describe

				// If Column Name is only the name of the referenced table, or the name of the referenced table with "_id",
				// then the object description is simply based off the table name.
				if (($strColumnName == $strReferencedTableName) ||
					($strColumnName == $strReferencedTableName . '_id'))
					return sprintf('Child%s', $strToReturn);

				// Rip out trailing "_id" if applicable
				$intLength = strlen($strColumnName);
				if (($intLength > 3) && (substr($strColumnName, $intLength - 3) == "_id"))
					$strColumnName = substr($strColumnName, 0, $intLength - 3);

				// Rip out the referenced table name from the column name
				$strColumnName = str_replace($strReferencedTableName, "", $strColumnName);

				// Change any double "_" to single "_"
				$strColumnName = str_replace("__", "_", $strColumnName);
				$strColumnName = str_replace("__", "_", $strColumnName);

				$strColumnName = QConvertNotation::CamelCaseFromUnderscore($strColumnName);

				// Special case for Parent/Child
				if ($strColumnName == 'Parent')
					return sprintf('Child%s', $strToReturn);

				return sprintf("%sAs%s",
					$strToReturn, $strColumnName);

			} else {
				// If Column Name is only the name of the referenced table, or the name of the referenced table with "_id",
				// then the object description is simply based off the table name.
				if (($strColumnName == $strReferencedTableName) ||
					($strColumnName == $strReferencedTableName . '_id'))
					return $strToReturn;

				// Rip out trailing "_id" if applicable
				$intLength = strlen($strColumnName);
				if (($intLength > 3) && (substr($strColumnName, $intLength - 3) == "_id"))
					$strColumnName = substr($strColumnName, 0, $intLength - 3);

				// Rip out the referenced table name from the column name
				$strColumnName = str_replace($strReferencedTableName, "", $strColumnName);

				// Change any double "_" to single "_"
				$strColumnName = str_replace("__", "_", $strColumnName);
				$strColumnName = str_replace("__", "_", $strColumnName);

				return sprintf("%sAs%s",
					$strToReturn,
					QConvertNotation::CamelCaseFromUnderscore($strColumnName));
			}
		}

		// this is called for ReverseReference Object Descriptions for association tables (many-to-many)
		protected function CalculateObjectDescriptionForAssociation($strAssociationTableName, $strTableName, $strReferencedTableName, $blnPluralize) {
			// Strip Prefixes (if applicable)
			$strTableName = $this->StripPrefixFromTable($strTableName);
			$strAssociationTableName = $this->StripPrefixFromTable($strAssociationTableName);
			$strReferencedTableName = $this->StripPrefixFromTable($strReferencedTableName);

			// Starting Point
			$strToReturn = QConvertNotation::CamelCaseFromUnderscore($strReferencedTableName);

			if ($blnPluralize)
				$strToReturn = $this->Pluralize($strToReturn);

			// Let's start with strAssociationTableName

			// Rip out trailing "_assn" if applicable
			$strAssociationTableName = str_replace($this->strAssociationTableSuffix, '', $strAssociationTableName);

			// remove instances of the table names in the association table name
			$strTableName2 = str_replace('_', '', $strTableName); // remove underscores if they are there
			$strReferencedTableName2 = str_replace('_', '', $strReferencedTableName); // remove underscores if they are there

			if (beginsWith ($strAssociationTableName, $strTableName . '_')) {
				$strAssociationTableName = trimOffFront ($strTableName . '_', $strAssociationTableName);
			} elseif (beginsWith ($strAssociationTableName, $strTableName2 . '_')) {
				$strAssociationTableName = trimOffFront ($strTableName2 . '_', $strAssociationTableName);
			} elseif (beginsWith ($strAssociationTableName, $strReferencedTableName . '_')) {
				$strAssociationTableName = trimOffFront ($strReferencedTableName . '_', $strAssociationTableName);
			} elseif (beginsWith ($strAssociationTableName, $strReferencedTableName2 . '_')) {
				$strAssociationTableName = trimOffFront ($strReferencedTableName2 . '_', $strAssociationTableName);
			} elseif ($strAssociationTableName == $strTableName ||
					$strAssociationTableName == $strTableName2 ||
					$strAssociationTableName == $strReferencedTableName ||
					$strAssociationTableName == $strReferencedTableName2) {
				$strAssociationTableName = "";
			}

			if (endsWith ($strAssociationTableName,  '_' . $strTableName)) {
				$strAssociationTableName = trimOffEnd ('_' . $strTableName, $strAssociationTableName);
			} elseif (endsWith ($strAssociationTableName, '_' . $strTableName2)) {
				$strAssociationTableName = trimOffEnd ('_' . $strTableName2, $strAssociationTableName);
			} elseif (endsWith ($strAssociationTableName,  '_' . $strReferencedTableName)) {
				$strAssociationTableName = trimOffEnd ('_' . $strReferencedTableName, $strAssociationTableName);
			} elseif (endsWith ($strAssociationTableName, '_' . $strReferencedTableName2)) {
				$strAssociationTableName = trimOffEnd ('_' . $strReferencedTableName2, $strAssociationTableName);
			} elseif ($strAssociationTableName == $strTableName ||
					$strAssociationTableName == $strTableName2 ||
					$strAssociationTableName == $strReferencedTableName ||
					$strAssociationTableName == $strReferencedTableName2) {
				$strAssociationTableName = "";
			}

			// Change any double "__" to single "_"
			$strAssociationTableName = str_replace("__", "_", $strAssociationTableName);
			$strAssociationTableName = str_replace("__", "_", $strAssociationTableName);
			$strAssociationTableName = str_replace("__", "_", $strAssociationTableName);

			// If we have nothing left or just a single "_" in AssociationTableName, return "Starting Point"
			if (($strAssociationTableName == "_") || ($strAssociationTableName == ""))
				return sprintf("%s%s%s",
					$this->strAssociatedObjectPrefix,
					$strToReturn,
					$this->strAssociatedObjectSuffix);

			// Otherwise, add "As" and the predicate
			return sprintf("%s%sAs%s%s",
				$this->strAssociatedObjectPrefix,
				$strToReturn,
				QConvertNotation::CamelCaseFromUnderscore($strAssociationTableName),
				$this->strAssociatedObjectSuffix);
		}

		// This is called by AnalyzeAssociationTable to calculate the GraphPrefixArray for a self-referencing association table (e.g. directed graph)
		protected function CalculateGraphPrefixArray($objForeignKeyArray) {
			// Analyze Column Names to determine GraphPrefixArray
			if ((strpos(strtolower($objForeignKeyArray[0]->ColumnNameArray[0]), 'parent') !== false) ||
				(strpos(strtolower($objForeignKeyArray[1]->ColumnNameArray[0]), 'child') !== false)) {
				$strGraphPrefixArray[0] = '';
				$strGraphPrefixArray[1] = 'Parent';
			} else if ((strpos(strtolower($objForeignKeyArray[0]->ColumnNameArray[0]), 'child') !== false) ||
						(strpos(strtolower($objForeignKeyArray[1]->ColumnNameArray[0]), 'parent') !== false)) {
				$strGraphPrefixArray[0] = 'Parent';
				$strGraphPrefixArray[1] = '';
			} else {
				// Use Default Prefixing for Graphs
				$strGraphPrefixArray[0] = 'Parent';
				$strGraphPrefixArray[1] = '';
			}

			return $strGraphPrefixArray;
		}

		/**
		 * Given a database field type, returns the data type which QCubed will use to treat that field
		 * @param string $strDbType
		 *
		 * @return string
		 * @throws Exception
		 */
		protected function VariableTypeFromDbType($strDbType) {
			switch ($strDbType) {
				case QDatabaseFieldType::Bit:
					return QType::Boolean;
				case QDatabaseFieldType::Blob:
					return QType::String;
				case QDatabaseFieldType::Char:
					return QType::String;
				case QDatabaseFieldType::Date:
					return QType::DateTime;
				case QDatabaseFieldType::DateTime:
					return QType::DateTime;
				case QDatabaseFieldType::Float:
					return QType::Float;
				case QDatabaseFieldType::Integer:
					return QType::Integer;
				case QDatabaseFieldType::Time:
					return QType::DateTime;
				case QDatabaseFieldType::VarChar:
					return QType::String;
				default:
					throw new Exception("Invalid Db Type to Convert: $strDbType");
			}
		}

		/**
		 * Given a word, returns the plural form of that word
		 * Used to convert words at certain places in generated drafts
		 * @param string $strName
		 *
		 * @return string
		 */
		protected function Pluralize($strName) {
			// Special Rules go Here
			switch (true) {
				case (strtolower($strName) == 'play'):
					return $strName . 's';
			}

			$intLength = strlen($strName);
			if (substr($strName, $intLength - 1) == "y")
				return substr($strName, 0, $intLength - 1) . "ies";
			if (substr($strName, $intLength - 1) == "s")
				return $strName . "es";
			if (substr($strName, $intLength - 1) == "x")
				return $strName . "es";
			if (substr($strName, $intLength - 1) == "z")
				return $strName . "zes";
			if (substr($strName, $intLength - 2) == "sh")
				return $strName . "es";
			if (substr($strName, $intLength - 2) == "ch")
				return $strName . "es";

			return $strName . "s";
		}


		////////////////////
		// Public Overriders
		////////////////////

		/**
		 * Override method to perform a property "Get"
		 * This will get the value of $strName
		 *
		 * @param string $strName
		 *
		 * @throws Exception|QCallerException
		 * @return mixed
		 */
		public function __get($strName) {
			switch ($strName) {
				case 'Errors':
					return $this->strErrors;
				default:
					try {
						return parent::__get($strName);
					} catch (QCallerException $objExc) {
						$objExc->IncrementOffset();
						throw $objExc;
					}
			}
		}

		/**
		 * PHP magic method to set class properties
		 * @param string $strName
		 * @param string $mixValue
		 *
		 * @return mixed
		 */
		public function __set($strName, $mixValue) {
			try {
				switch($strName) {
					case 'Errors':
						return ($this->strErrors = QType::Cast($mixValue, QType::String));
					default:
						return parent::__set($strName, $mixValue);
				}
			} catch (QCallerException $objExc) {
				$objExc->IncrementOffset();
			}
		}
	}
?>