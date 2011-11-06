<?php
/**
 *
 */

class XSD2PHP {

	const APPLICATION = 'JW-XSD to PHP';
	const VERSION     = '0.0.1';

	protected $classFolder;
	protected $XSDFolder;
	protected $DOMDocument;
	protected $DOMXPath;

	static $enumerations = array();

	public function __construct() {
		$this->classFolder = __DIR__.'/classes/';
		$this->XSDFolder   = __DIR__.'/xsd/';
	}

	public function initialize() {

	}

	public function analyze($XSDFile) {
		var_dump('Analyzing '.$XSDFile);
		$fileName = $this->XSDFolder.$XSDFile;

		$this->DOMDocument = new DOMDocument();
		$this->DOMDocument->preserveWhiteSpace = false;

		$this->DOMDocument->load($fileName);
		$this->DOMXPath = new DOMXPath($this->DOMDocument);

		$includes     = $this->DOMXPath->query('//xsd:schema/xsd:include');
		$simpleTypes  = $this->DOMXPath->query('//xsd:schema/xsd:simpleType');
		$complexTypes = $this->DOMXPath->query('//xsd:schema/xsd:complexType');
		$elements     = $this->DOMXPath->query('//xsd:schema/xsd:element');

		foreach ($includes as $include) {
			$XSD2PHP = new XSD2PHP();
			$XSD2PHP->analyze($include->getAttribute('schemaLocation'));
		}

		foreach ($simpleTypes as $simpleType) {
			$this->saveSimpleType($simpleType);
		}

		foreach ($complexTypes as $complexType) {
			$this->saveComplexType($complexType);
		}

		foreach ($elements as $element) {
			$this->saveElement($element);
		}

	}

	protected function saveSimpleType($element) {

//<xsd:simpleType name="MonthType">
//	<xsd:annotation>
//		<xsd:documentation>
//			<definition>Month as 2 digit integer</definition>
//		</xsd:documentation>
//	</xsd:annotation>
//	<xsd:restriction base="xsd:integer">
//		<xsd:minInclusive value="1"/>
//		<xsd:maxInclusive value="12"/>
//	</xsd:restriction>
//</xsd:simpleType>

		$name = $element->getAttribute('name');
		$elements = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction/xsd:enumeration');
		$documentation = $this->formatDocumentation($this->getDocumentation($element, 'simpleType'), false);

		$constVars = array();
		foreach ($elements as $variable) {
			$const = $value = $variable->attributes->getNamedItem('value')->nodeValue;
			if (is_numeric($value)) {
				$const = '_'.$const;
			}
			$constVars[] = "\tconst ".$const.' = '.$value.';';
		}
		$phpCode = '<?php'.PHP_EOL.
			'/**'.PHP_EOL.
			' * @author '.APPLICATION.' ('.VERSION.')'.PHP_EOL.
			' * @version 0.1'.PHP_EOL.
			' * @since '.date('Y-m-d H:i:s').PHP_EOL.
			' * '.$documentation.PHP_EOL.
			' */'.PHP_EOL.PHP_EOL.
			'class '.$name.' {'.PHP_EOL.PHP_EOL.
			implode(PHP_EOL, $constVars).PHP_EOL.
			'}'.PHP_EOL.PHP_EOL;

		file_put_contents($this->classFolder.'/'.$name.'.php', $phpCode);
		self::$enumerations[] = $name;
	}

	protected function formatDocumentation($documentation, $indent = true) {
		if ($indent) {
			$prefix = PHP_EOL."\t * ";
		} else {
			$prefix = PHP_EOL." * ";
		}
		$documentation = trim($documentation);
		$documentation = str_replace(array("\r", "\n\n"), array("\n", "\n"), $documentation);
		$lines = explode("\n", $documentation);

		foreach ($lines as $index => $line) {
			$line = trim($line);
			if (strlen($line) < 75) {
				$lines[$index] = $line;
				continue;
			}

			$newLines = array();
			$token = strtok($line, ' ');
			$counter = 0;
			while ($token !== false) {
				if (strlen($newLines[$counter].$token.' ') >= 75) {
					$counter++;
				}
				$newLines[$counter] .= $token.' ';
				$token = strtok(' ');
			}

			$lines[$index] = trim(implode($prefix, $newLines));
		}
		$documentation = implode($prefix, $lines);
		return $documentation;
	}

	protected function getDocumentation($element, $elementType = 'element') {
		$name = $element->getAttribute('name');
		$xpathQuery = '//xsd:'.$elementType.'[@name="'.$name.'"]/xsd:annotation/xsd:documentation';
		if (empty($name)) {
			$name = $element->getAttribute('ref');
			$xpathQuery = '//xsd:'.$elementType.'[@ref="'.$name.'"]/xsd:annotation/xsd:documentation';
		}
		$documentation = $this->DOMXPath->query($xpathQuery);
		if ($documentation->length == 0) {
			$return = '...';
		} else {
			$return = $documentation->item(0)->getElementsByTagName('definition')->item(0)->nodeValue;
			if (empty($return)) {
				$return = $documentation->item(0)->nodeValue;
			}
		}
		return $return;
	}


	protected function saveVariables($name, $elements, $documentation) {

		$phpVars = array();

		foreach ($elements as $variable) {
			$phpVars[] = $this->getElement($variable);
		}

		$phpCode = '<?php'.PHP_EOL.
			'/**'.PHP_EOL.
			' * '.$documentation.PHP_EOL.
			' * @author '.APPLICATION.' ('.VERSION.')'.PHP_EOL.
			' * @version 0.1'.PHP_EOL.
			' * @since '.date('Y-m-d H:i:s').PHP_EOL.
			' */'.PHP_EOL.PHP_EOL.
			'class '.$name.' {'.PHP_EOL.PHP_EOL.
			implode('', $phpVars).
			'}'.PHP_EOL.PHP_EOL;

		file_put_contents($this->classFolder.'/'.$name.'.php', $phpCode);
	}

	protected function saveElement($element) {

		$name = $element->getAttribute('name');
		$elements = $this->DOMXPath->query('//xsd:element[@name="'.$name.'"]/xsd:complexType/xsd:sequence/xsd:element');
		$documentation = $this->formatDocumentation($this->getDocumentation($element), false);
		$this->saveVariables($name, $elements, $documentation);
	}

	protected function saveComplexType($element) {
		$name = $element->getAttribute('name');
		$elements = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'"]/xsd:sequence/xsd:element');
		$documentation = $this->formatDocumentation($this->getDocumentation($element, 'complexType'), false);
		$this->saveVariables($name, $elements, $documentation);
	}

	protected function getElement($element) {

		$name = $element->getAttribute('name');
		if (empty($name)) {
			$name = $element->getAttribute('ref');
			$type = $name;
		} else {
			$type = $element->attributes->getNamedItem('type')->nodeValue;
		}
		$minOccurs = $element->getAttribute('minOccurs');
		if (empty($minOccurs)) {
			$minOccurs = 0;
		}
		$maxOccurs = $element->getAttribute('maxOccurs');
		if (empty($maxOccurs)) {
			$maxOccurs = 1;
		}
		$cardinality = $minOccurs == $maxOccurs ? $minOccurs : $minOccurs.'..'.$maxOccurs;

		if (in_array($type, self::$enumerations)) {
			$codeList = "\t".' * codelist: '.$type.PHP_EOL;
		} else {
			$codeList = '';
		}

		$documentation = $this->formatDocumentation($this->getDocumentation($element));

		$return = "\t".'/**'.PHP_EOL.
			"\t".' * '.$documentation.PHP_EOL.
			"\t".' * '.'Cardinality: '.$cardinality.PHP_EOL.
			$codeList.
			"\t".' * '.'@var '.$type.PHP_EOL.
			"\t".' */'.PHP_EOL.
			"\t".'protected $'.$name.';'.
			PHP_EOL;

		return $return;
	}
}
