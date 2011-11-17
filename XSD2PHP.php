<?php
/**
 * TODO: make it recursive by analysing the elements
 *
 * TODO: implement more restrictions and enumerations
 * http://zvon.org/xxl/XMLSchemaTutorial/Output/highlights.html
 * TODO: invalid variable names (example $E-mail)
 *
 */

class XSD2PHP {

	const APPLICATION = 'JW-XSD to PHP';
	const VERSION     = '0.0.1';

	CONST LINELENGTH  = 75;

	protected $classFolder;
	protected $XSDFolder;
	protected $DOMDocument;
	protected $DOMXPath;

	static $enumerations = array();

	public function __construct() {
		$this->classFolder = __DIR__.'/classes/';
		$this->XSDFolder   = __DIR__.'/xsd/';
	}

	protected function initialize($XSDFile) {
		$fileName = $this->XSDFolder.$XSDFile;

		$this->DOMDocument = new DOMDocument();
		$this->DOMDocument->preserveWhiteSpace = false;

		$this->DOMDocument->load($fileName);
		$this->DOMXPath = new DOMXPath($this->DOMDocument);
	}

	public function parse($XSDFile) {
		/**
		 * http://schemas.monster.com/Current/XSD/JobDocumentation.html
		 */
		$this->initialize($XSDFile);
		$elements = $this->DOMXPath->query('//xsd:schema/*');
		foreach ($elements as $element) {
			$this->parseElement($element);
		}
	}
	// TODO rename to parseElements
	protected function parseElement(DOMNode $element) {
		switch ($element->nodeName) {
			case 'xsd:include':
				$this->includeXSD($element->getAttribute('schemaLocation'));
				break;
			case 'xsd:element':
				$this->parseElementType($element);
				break;
			case 'xsd:complexType':
//				$this->parseComplexType($element);
				break;
			case 'xsd:simpleType':
//				$this->parseSimpleType($element);
				break;
			default:
				throw new Exception('Unknown nodeName: '.$element->nodeName.': '.$element->getAttribute('name'));
				break;
		}
	}

	protected function parseElementType($element) {
		$name = $element->getAttribute('name');
		$documentation = $this->formatDocumentation($this->getDocumentation($element), false);

		$attributes = $extends = '';
		$variables = array();

		if ($element->hasAttribute('type')) {
			$extends = $element->getAttribute('type');
		} else {
			$variables = $this->parseVariables($name, 'element');
			$attributes = $this->formatAttributes(
				$this->parseAttributes('element', $name)
			);
		}

		$this->saveClass(
			$name,
			$documentation,
			$attributes,
			$variables,
			$extends
		);
	}

	protected function parseSimpleType($element) {
		$name = $element->getAttribute('name');

		$documentation = $this->formatDocumentation(
				$this->parseDocumentation('simpleType', $name),
				false
			);

		$enumerations = $this->parseEnumeration($name);

		$this->saveClass(
			$name,
			$documentation,
			implode(PHP_EOL, $enumerations),
			array()
		);

	}

	protected function parseEnumerationSimple($name, $type ='simpleType') {
		$constVars = array();

		switch ($type) {
			case 'attribute':
				$XPathQuery= '//xsd:attribute[@name="'.$name.'"]/xsd:simpleType/xsd:restriction';
				break;
			case 'simpleType':
				$XPathQuery= '//xsd:simpleType[@name="'.$name.'"]/xsd:restriction';
				break;
		}
		$restrictions = $this->DOMXPath->query($XPathQuery);
		if ($restrictions->length == 0) {
			return '';
		}
		$restriction = $restrictions->item(0)->attributes->getNamedItem('base')->nodeValue;

		switch ($restriction) {
			case 'xsd:string':
				$enumerations = $this->DOMXPath->query($XPathQuery.'/xsd:enumeration');
				foreach ($enumerations as $enumeration) {
					$value = $enumeration->attributes->getNamedItem('value')->nodeValue;
					$constVars[] = $value;
				}

				return $restriction.' '.implode('|', $constVars);
				break;

			case 'xsd:integer':
				// TODO: other valid restrictions
				$minInclusive = $this->DOMXPath->query($XPathQuery.'/xsd:minInclusive')
					->item(0)->attributes->getNamedItem('value')->nodeValue;
				$maxInclusive = $this->DOMXPath->query($XPathQuery.'/xsd:maxInclusive')
					->item(0)->attributes->getNamedItem('value')->nodeValue;

				if (is_null($minInclusive) || is_null($maxInclusive)) {
					throw new Exception($name.' has restriction '.$restriction.' but no minInclusive or maxInclusive');
				}
				return $restriction.' minInclusive:'.$minInclusive.'|maxInclusive:'.$maxInclusive;
				break;

			case 'xsd:decimal':
				// TODO: other valid restrictions
				$fractionDigits = $this->DOMXPath->query($XPathQuery.'/xsd:fractionDigits')
					->item(0)->attributes->getNamedItem('value')->nodeValue;
				$minInclusive = $this->DOMXPath->query($XPathQuery.'/xsd:minInclusive')
					->item(0)->attributes->getNamedItem('value')->nodeValue;

				if (is_null($fractionDigits) || is_null($minInclusive)) {
					throw new Exception($name.' has restriction '.$restriction.' but no fractionDigits or minInclusive');
				}
					return $restriction.' fractionDigits:'.$fractionDigits.'|minInclusive:'.$minInclusive;
				break;

			default:
				echo 'Unknown restriction type '. $restriction.PHP_EOL;
				die;
				break;
		}
		return $constVars;
	}

	// TODO could use parseEnumerationSimple
	protected function parseEnumeration($name, $type ='simpleType') {
		$constVars = array();

		switch ($type) {
			case 'attribute':
				$XPathQuery= '//xsd:attribute[@name="'.$name.'"]/xsd:simpleType/xsd:restriction';
				break;
			case 'simpleType':
				$XPathQuery= '//xsd:simpleType[@name="'.$name.'"]/xsd:restriction';
				break;
		}
		$restriction = $this->DOMXPath->query($XPathQuery)->item(0)->attributes->getNamedItem('base')->nodeValue;

		switch ($restriction) {
			case 'xsd:string':
				$enumerations = $this->DOMXPath->query($XPathQuery.'/xsd:enumeration');
				foreach ($enumerations as $enumeration) {
					$const = $value = $enumeration->attributes->getNamedItem('value')->nodeValue;
					if (is_numeric($value)) {
						$const = '_'.str_replace(array('-', '+', '.', ','), '_', $const);
					} else {
						$const = strtoupper($const);
						$value = '\''.$value.'\'';
					}
					$constVars[] = "\tconst ".$const.' = '.$value.';';
				}
				break;

			case 'xsd:integer':
				// TODO: other valid restrictions
				$minInclusive = $this->DOMXPath->query($XPathQuery.'/xsd:minInclusive')
					->item(0)->attributes->getNamedItem('value')->nodeValue;
				$maxInclusive = $this->DOMXPath->query($XPathQuery.'/xsd:maxInclusive')
					->item(0)->attributes->getNamedItem('value')->nodeValue;

				if (is_null($minInclusive) || is_null($maxInclusive)) {
					throw new Exception($name.' has restriction '.$restriction.' but no minInclusive or maxInclusive');
				}
				$constVars[] = "\tprotected \$range = array(".PHP_EOL.
					"\t\t 'minInclusive' => ".$minInclusive.','.PHP_EOL.
					"\t\t 'maxInclusive' => ".$maxInclusive.PHP_EOL.
					"\t);";
				break;

			case 'xsd:decimal':
				// TODO: other valid restrictions
				$fractionDigits = $this->DOMXPath->query($XPathQuery.'/xsd:fractionDigits')
					->item(0)->attributes->getNamedItem('value')->nodeValue;
				$minInclusive = $this->DOMXPath->query($XPathQuery.'/xsd:minInclusive')
					->item(0)->attributes->getNamedItem('value')->nodeValue;

				if (is_null($fractionDigits) || is_null($minInclusive)) {
					throw new Exception($name.' has restriction '.$restriction.' but no fractionDigits or minInclusive');
				}
				$constVars[] = "\tprotected \$valid = array(".PHP_EOL.
					"\t\t 'fractionDigits' => ".$fractionDigits.','.PHP_EOL.
					"\t\t 'minInclusive'   => ".$minInclusive.PHP_EOL.
					"\t);";
				break;

			default:
				echo 'Unknown restriction type '. $restriction.PHP_EOL;
				die;
				break;
		}
		return $constVars;
	}

	protected function parseComplexType($element) {

		$name = $element->getAttribute('name');

		$documentation = $this->formatDocumentation(
				$this->parseDocumentation('complexType', $name),
				false
			);

		$attributes = $this->formatAttributes(
			$this->parseAttributes('complexType', $name)
		);

		$variables = $this->parseVariables($name);

		$this->saveClass(
			$name,
			$documentation,
			$attributes,
			$variables
		);
	}

//<xsd:complexType name="CompensationTypeEnumStr">
//	<xsd:annotation>
//		<xsd:documentation>
//1	Per Year
//2	Per Hour
//3	Per Week
//4	Per Month
//5	Biweekly
//6	Per Day
//		</xsd:documentation>
//	</xsd:annotation>
//	<xsd:simpleContent>
//		<xsd:extension base="xsd:string">
//			<xsd:attribute name="monsterId" type="CompensationTypeIdEnum" use="required"/>
//		</xsd:extension>
//	</xsd:simpleContent>
//</xsd:complexType>

	protected function parseVariables($name, $type = 'complexType') {
		$variables = array();
		switch ($type) {
			case 'element':
				$XPathQuery= '//xsd:element[@name="'.$name.'"]/xsd:complexType/xsd:sequence/xsd:element';
				break;
			case 'complexType':
				$XPathQuery = '//xsd:complexType[@name="'.$name.'"]/xsd:sequence/xsd:element';
				break;
		}
		$elements = $this->DOMXPath->query($XPathQuery);
		foreach ($elements as $element) {
			$variables[] = $this->getElement($element);
		}
		return $variables;
	}

	protected function formatAttributes($attributes) {
		if (count($attributes) == 0) {
			return '';
		}

		$return = $docBlock = array();

		foreach ($attributes as $attribute) {

			$return[] = "\t\t/* {$attribute['docu']} */".PHP_EOL.
				"\t\t '{$attribute['name']}' => array(".PHP_EOL.
				"\t\t\t'attribute' => '{$attribute['name']}',".PHP_EOL.
				"\t\t\t/* @var {$attribute['type']} */".PHP_EOL.
				"\t\t\t'codelist' => '{$attribute['type']}'".PHP_EOL.
				"\t\t)";

			$docBlock['cardinality'][] = '@cardinality '.($attribute['use'] == 'required' ? '1' : '0..1').' '.$attribute['name'];

			$docBlock['var'][]         = '@var '.$attribute['type'].' '.$attribute['name'];
		}

		$return = "\t/**".PHP_EOL.
			"\t * ".implode(PHP_EOL."\t * ", $docBlock['cardinality']).PHP_EOL.
			"\t * ".implode(PHP_EOL."\t * ", $docBlock['var']).PHP_EOL.
			"\t * @var array".PHP_EOL.
			"\t */".PHP_EOL.
			"\tprotected \$attributes = array(".PHP_EOL.implode(','.PHP_EOL, $return).PHP_EOL."\t);".PHP_EOL;

		return $return;

	}

// TODO
//<xsd:attribute name="questionResponseType" use="required">
//	<xsd:simpleType>
//		<xsd:restriction base="xsd:string">
//			<xsd:enumeration value="Text"/>
//			<xsd:enumeration value="Boolean"/>
//			<xsd:enumeration value="Numeric"/>
//			<xsd:enumeration value="AnswerId"/>
//			<xsd:enumeration value="BooleanMaybe"/>
//		</xsd:restriction>
//	</xsd:simpleType>
//</xsd:attribute>

	protected function parseDocumentation($type, $name, $attribute = 'name') {

		$return = null;

		$documentation = $this->DOMXPath->query('//xsd:'.$type.'[@'.$attribute.'="'.$name.'"]/xsd:annotation/xsd:documentation');

		if ($documentation->length) {

			$return = $documentation->item(0)->getElementsByTagName('definition')->item(0)->nodeValue;

			if (empty($return)) {

				$return = $documentation->item(0)->nodeValue;

			}
		}

		if (empty($return)) {
			$return = '...';
		}

		return $return;
	}

	protected function parseAttributes($type, $name) {

		$return = array();

		$attributes = $this->DOMXPath->query('//xsd:'.$type.'[@name="'.$name.'"]//xsd:attribute');

		foreach ($attributes as $attribute) {
			$name = $attribute->getAttribute('name');
			$type = $attribute->getAttribute('type');
			if (empty($type)) {
				$type = $this->parseEnumerationSimple($name, 'attribute');
			}
			$return[] = array(
				'name' => $name,
				'type' => $type,
				'use'  => $attribute->getAttribute('use'),
				'docu' => $this->formatDocumentation(
					$this->parseDocumentation('attribute', $name),
					2
				)
			);

		}

		return $return;
	}

//$name = $element->getAttribute('name');
//$xpathQuery = '//xsd:'.$elementType.'[@name="'.$name.'"]/xsd:annotation/xsd:documentation';
//if (empty($name)) {
//	$name = $element->getAttribute('ref');
//	$xpathQuery = '//xsd:'.$elementType.'[@ref="'.$name.'"]/xsd:annotation/xsd:documentation';
//}
//$documentation = $this->DOMXPath->query($xpathQuery);
//if ($documentation->length == 0) {
//	$return = '...';
//} else {
//	$return = $documentation->item(0)->getElementsByTagName('definition')->item(0)->nodeValue;
//	if (empty($return)) {
//		$return = $documentation->item(0)->nodeValue;
//	}
//}
//return $return;

	protected function saveClass($className, $classComment = '', $attributes = '', $variables = array(), $extendedClass = '') {

		$phpCode = '<?php'.PHP_EOL.
			'/**'.PHP_EOL.
			' * '.$classComment.PHP_EOL.
			' * @author '.self::APPLICATION.' ('.self::VERSION.')'.PHP_EOL.
			' * @version 0.1'.PHP_EOL.
			' * @since '.date('Y-m-d H:i:s').PHP_EOL.
			' */'.PHP_EOL.PHP_EOL.
			'class '.$className.(empty($extendedClass) ? '' : ' extends '.$extendedClass).' {'.PHP_EOL.PHP_EOL.
			$attributes.PHP_EOL.
			implode(PHP_EOL, $variables).PHP_EOL.
			'}'.PHP_EOL.PHP_EOL;

		file_put_contents($this->classFolder.'/'.$className.'.php', $phpCode);
//		echo 'Saved '.$className.PHP_EOL;
	}
//<Salary>
//  <Currency monsterId="37" />
//  <SalaryMin>100000</SalaryMin>
//  <SalaryMax>150000</SalaryMax>
//  <CompensationType monsterId="1" />
//</Salary>
//
//...
//<xsd:element name="Salary" type="JobSalaryType" minOccurs="0">
//	<xsd:annotation>
//		<xsd:documentation>
//			<definition>The salary paid for the Job. Can be min/max, range, or currency per time period</definition>
//		</xsd:documentation>
//	</xsd:annotation>
//</xsd:element>
//...
//
//<xsd:complexType name="JobSalaryType">
//	<xsd:sequence>
//		<xsd:element name="Currency" type="CurrencyType" minOccurs="0">
//			<xsd:annotation>
//				<xsd:documentation>The currency of the salary</xsd:documentation>
//			</xsd:annotation>
//		</xsd:element>
//		<xsd:element name="SalaryMin" type="MoneyType" minOccurs="0">
//			<xsd:annotation>
//				<xsd:documentation>The minimum salary</xsd:documentation>
//			</xsd:annotation>
//		</xsd:element>
//		<xsd:element name="SalaryMax" type="MoneyType" minOccurs="0">
//			<xsd:annotation>
//				<xsd:documentation>The maximum salary</xsd:documentation>
//			</xsd:annotation>
//		</xsd:element>
//		<xsd:element name="CompensationType" type="CompensationTypeEnumStr" minOccurs="0">
//			<xsd:annotation>
//				<xsd:documentation>The frequency of pay</xsd:documentation>
//			</xsd:annotation>
//		</xsd:element>
//		<xsd:element name="SalaryDescription" type="xsd:string" minOccurs="0">
//			<xsd:annotation>
//				<xsd:documentation>Description of the total compensation package provided by the company</xsd:documentation>
//			</xsd:annotation>
//		</xsd:element>
//		<xsd:element name="SalaryRange" type="SalaryRangeType" minOccurs="0">
//			<xsd:annotation>
//				<xsd:documentation>Used for chief jobs only.</xsd:documentation>
//			</xsd:annotation>
//		</xsd:element>
//	</xsd:sequence>
//</xsd:complexType>
//
//<xsd:complexType name="CompensationTypeEnumStr">
//	<xsd:annotation>
//		<xsd:documentation>
//1	Per Year
//2	Per Hour
//3	Per Week
//4	Per Month
//5	Biweekly
//6	Per Day
//		</xsd:documentation>
//	</xsd:annotation>
//	<xsd:simpleContent>
//		<xsd:extension base="xsd:string">
//			<xsd:attribute name="monsterId" type="CompensationTypeIdEnum" use="required"/>
//		</xsd:extension>
//	</xsd:simpleContent>
//</xsd:complexType>
//<xsd:simpleType name="CompensationTypeIdEnum">
//	<xsd:restriction base="xsd:string">
//		<xsd:enumeration value="1"/>
//		<xsd:enumeration value="2"/>
//		<xsd:enumeration value="3"/>
//		<xsd:enumeration value="4"/>
//		<xsd:enumeration value="5"/>
//		<xsd:enumeration value="6"/>
//	</xsd:restriction>
//</xsd:simpleType>

/**
 * from here legacy code
 */
	protected function includeXSD($XSDFile) {
		echo 'including '.$XSDFile.PHP_EOL;
		$XSD2PHP = new XSD2PHP();
		$XSD2PHP->parse($XSDFile);
	}

	public function analyze($XSDFile) {
//		var_dump('Analyzing '.$XSDFile);
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

		$name = $element->getAttribute('name');
		$documentation = $this->formatDocumentation($this->getDocumentation($element, 'simpleType'), false);

		$constVars = array();

		$elements = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction/xsd:enumeration');
		if ($elements->length != 0) {
			foreach ($elements as $variable) {
				$const = $value = $variable->attributes->getNamedItem('value')->nodeValue;
				if (is_numeric($value)) {
					$const = '_'.str_replace(array('-', '+', '.', ','), '_', $const);
				} else {
					$const = strtoupper($const);
					$value = '\''.$value.'\'';
				}
				$constVars[] = "\tconst ".$const.' = '.$value.';';
			}
		} else {

			$restriction = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction')->item(0)->attributes->getNamedItem('base')->nodeValue;
			switch ($restriction) {
				// TODO: http://zvon.org/xxl/XMLSchemaTutorial/Output/highlights.html
				case 'xsd:string':
					// TODO: don't know what to do for the moment
					break;
				case 'xsd:integer':
					// TODO: other valid restrictions
					$minInclusive = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction/xsd:minInclusive')
						->item(0)->attributes->getNamedItem('value')->nodeValue;
					$maxInclusive = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction/xsd:maxInclusive')
						->item(0)->attributes->getNamedItem('value')->nodeValue;

					if (is_null($minInclusive) || is_null($maxInclusive)) {
						throw new Exception($name.' has restriction '.$restriction.' but no minInclusive or maxInclusive');
					}
					$constVars[] = "\tprotected \$range = array(".PHP_EOL.
						"\t\t 'minInclusive' => ".$minInclusive.','.PHP_EOL.
						"\t\t 'maxInclusive' => ".$maxInclusive.PHP_EOL.
						"\t);";
				break;
				case 'xsd:decimal':
					// TODO: other valid restrictions
					$fractionDigits = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction/xsd:fractionDigits')
						->item(0)->attributes->getNamedItem('value')->nodeValue;
					$minInclusive = $this->DOMXPath->query('//xsd:simpleType[@name="'.$name.'"]/xsd:restriction/xsd:minInclusive')
						->item(0)->attributes->getNamedItem('value')->nodeValue;

					if (is_null($fractionDigits) || is_null($minInclusive)) {
						throw new Exception($name.' has restriction '.$restriction.' but no fractionDigits or minInclusive');
					}
					$constVars[] = "\tprotected \$valid = array(".PHP_EOL.
						"\t\t 'fractionDigits' => ".$fractionDigits.','.PHP_EOL.
						"\t\t 'minInclusive'   => ".$minInclusive.PHP_EOL.
						"\t);";
					break;
				default:
					var_dump('Need to enumerate '.$name.': '.$restriction);die;
					break;
			}
		}

		$phpCode = '<?php'.PHP_EOL.
			'/**'.PHP_EOL.
			' * '.$documentation.PHP_EOL.
			' * @author '.self::APPLICATION.' ('.self::VERSION.')'.PHP_EOL.
			' * @version 0.1'.PHP_EOL.
			' * @since '.date('Y-m-d H:i:s').PHP_EOL.
			' */'.PHP_EOL.PHP_EOL.
			'class '.$name.' {'.PHP_EOL.PHP_EOL.
			implode(PHP_EOL, $constVars).PHP_EOL.
			'}'.PHP_EOL.PHP_EOL;

		file_put_contents($this->classFolder.'/'.$name.'.php', $phpCode);
		self::$enumerations[] = $name;
	}

	protected function formatDocumentation($documentation, $indent = 1) {
		if ($indent) {
			$prefix = PHP_EOL.str_repeat("\t", $indent).' * ';
		} else {
			$prefix = PHP_EOL." * ";
		}
		$documentation = trim($documentation);
		$documentation = str_replace(array("\r", "\n\n"), array("\n", "\n"), $documentation);
		$lines = explode("\n", $documentation);

		foreach ($lines as $index => $line) {
			$line = trim($line);
			if (strlen($line) < self::LINELENGTH) {
				$lines[$index] = $line;
				continue;
			}

			$newLines = array();
			$token = strtok($line, ' ');
			$counter = 0;
			while ($token !== false) {
				if (strlen($newLines[$counter].$token.' ') >= self::LINELENGTH) {
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
			' * @author '.self::APPLICATION.' ('.self::VERSION.')'.PHP_EOL.
			' * @version 0.1'.PHP_EOL.
			' * @since '.date('Y-m-d H:i:s').PHP_EOL.
			' */'.PHP_EOL.PHP_EOL.
			'class '.$name.' {'.PHP_EOL.PHP_EOL.
			implode('', $phpVars).
			'}'.PHP_EOL.PHP_EOL;

		file_put_contents($this->classFolder.'/'.$name.'.php', $phpCode);
	}

	protected function saveExtends($name, $baseClass, $documentation) {
		$phpCode = '<?php'.PHP_EOL.
			'/**'.PHP_EOL.
			' * '.$documentation.PHP_EOL.
			' * @author '.self::APPLICATION.' ('.self::VERSION.')'.PHP_EOL.
			' * @version 0.1'.PHP_EOL.
			' * @since '.date('Y-m-d H:i:s').PHP_EOL.
			' */'.PHP_EOL.PHP_EOL.
			'class '.$name.' extends '.$baseClass.' {'.PHP_EOL.PHP_EOL.
			'}'.PHP_EOL.PHP_EOL;

		file_put_contents($this->classFolder.'/'.$name.'.php', $phpCode);
	}

	protected function saveElement($element) {

		$name = $element->getAttribute('name');
		$documentation = $this->formatDocumentation($this->getDocumentation($element), false);

		if ($element->hasAttribute('type')) {
			$this->saveExtends($name, $element->getAttribute('type'), $documentation);
		} else {
			$elements = $this->DOMXPath->query('//xsd:element[@name="'.$name.'"]/xsd:complexType/xsd:sequence/xsd:element');
			$this->saveVariables($name, $elements, $documentation);
		}
	}

	protected function saveComplexType($element) {
		$name = $element->getAttribute('name');
		$elements = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'"]/xsd:sequence/xsd:element');
		$documentation = $this->formatDocumentation($this->getDocumentation($element, 'complexType'), false);
		if ($elements->length == 0) {
			// extension from base
			$extensions = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'"]/xsd:complexContent/xsd:extension');
			if ($extensions->length != 0) {
				$baseName = $extensions->item(0)->attributes->getNamedItem('base')->nodeValue;
				$elements = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'" or @name="'.$baseName.'"]//xsd:sequence/xsd:element');
			}
		}


		if ($elements->length != 0) {
			$this->saveVariables($name, $elements, $documentation);
		} else {
			switch ($name) {
				case 'DateTimeType':
				case 'DateType':

				case 'JobBodyType':

					return;
					break;
			}
//			var_dump($name);

//<xsd:complexType name="DateTimeType">
//	<xsd:simpleContent>
//		<xsd:extension base="xsd:dateTime"/>
//	</xsd:simpleContent>
//</xsd:complexType>
//<xsd:complexType name="DateType">
//	<xsd:choice>
//		<xsd:sequence>
//			<xsd:element name="Day" type="xsd:string" minOccurs="0"/>
//			<xsd:element name="Month" type="xsd:string" minOccurs="0"/>
//			<xsd:element name="Year" type="xsd:string" minOccurs="0"/>
//		</xsd:sequence>
//		<xsd:sequence>
//			<xsd:element name="Date" type="xsd:string" minOccurs="0"/>
//		</xsd:sequence>
//	</xsd:choice>
//</xsd:complexType>

//<xsd:complexType name="JobBodyType">
//	<xsd:simpleContent>
//		<xsd:extension base="xsd:string"/>
//	</xsd:simpleContent>
//</xsd:complexType>


			$enumerations = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'"]/xsd:attribute/xsd:simpleType/xsd:restriction/xsd:enumeration');
			if ($enumerations->length == 0) {
				$attribute = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'"]//xsd:attribute')->item(0);
				$attributeType = $attribute->attributes->getNamedItem('type')->nodeValue;
				$attributeName = $attribute->attributes->getNamedItem('name')->nodeValue;
				$codeList      = '\''.$attributeType.'\'';
			} else {

				$attribute = $this->DOMXPath->query('//xsd:complexType[@name="'.$name.'"]/xsd:attribute')->item(0);

				$attributeType = null;
				$attributeName = $attribute->attributes->getNamedItem('name')->nodeValue;
				$codeList = array();
				foreach ($enumerations as $enumeration) {
					$codeList[] = $enumeration->attributes->getNamedItem('value')->nodeValue;
				}
				$codeList = 'array(\''.implode('\', \'', $codeList).'\')';

			}

			$phpCode = '<?php'.PHP_EOL.
				'/**'.PHP_EOL.
				' * '.$documentation.PHP_EOL.
				' * @author '.self::APPLICATION.' ('.self::VERSION.')'.PHP_EOL.
				' * @version 0.1'.PHP_EOL.
				' * @since '.date('Y-m-d H:i:s').PHP_EOL.
				' */'.PHP_EOL.PHP_EOL.
				'class '.$name.' {'.PHP_EOL.PHP_EOL.
				"\t protected \$attributes = array(".PHP_EOL.
				"\t\t '$attributeName' => array(".PHP_EOL.
				"\t\t\t'attribute' => '$attributeName',".PHP_EOL.
				"\t\t\t/**".PHP_EOL.
				"\t\t\t * @var $attributeType".PHP_EOL.
				"\t\t\t */".PHP_EOL.
				"\t\t\t'codelist'  => $codeList".PHP_EOL.
				"\t\t)".PHP_EOL.
				"\t);".PHP_EOL.
				'}'.PHP_EOL.PHP_EOL;

			file_put_contents($this->classFolder.'/'.$name.'.php', $phpCode);

		}

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
// TODO:
//<xsd:complexType name="ApplicantResponseType">
//	<xsd:sequence>
//		<xsd:element name="Question" type="QuestionType"/>
//		<xsd:element name="QuestionResponse" minOccurs="0" maxOccurs="unbounded">
//			<xsd:complexType>
//				<xsd:simpleContent>
//					<xsd:extension base="xsd:string">
//						<xsd:attribute name="questionResponseType" use="required">
//							<xsd:simpleType>
//								<xsd:restriction base="xsd:string">
//									<xsd:enumeration value="Text"/>
//									<xsd:enumeration value="Boolean"/>
//									<xsd:enumeration value="Numeric"/>
//									<xsd:enumeration value="AnswerId"/>
//									<xsd:enumeration value="BooleanMaybe"/>
//								</xsd:restriction>
//							</xsd:simpleType>
//						</xsd:attribute>
//						<xsd:attribute name="questionResponseAnswerId" type="xsd:string" use="optional"/>
//					</xsd:extension>
//				</xsd:simpleContent>
//			</xsd:complexType>
//		</xsd:element>
//	</xsd:sequence>
//</xsd:complexType>

// TODO:
//<xsd:simpleType name="VeteransPreferenceIdEnum">
//	<xsd:annotation>
//		<xsd:documentation>
//		</xsd:documentation>
//	</xsd:annotation>
//	<xsd:restriction base="xsd:string">
//	</xsd:restriction>
//</xsd:simpleType>

// TODO:
//<xsd:element name="UserID" type="xsd:string">
//	<xsd:annotation>
//		<xsd:documentation>
//    UserID of the User of Job to return.
//  </xsd:documentation>
//	</xsd:annotation>
//</xsd:element>

//TODO: done
//<xsd:complexType name="StandardAAPJobGroupEnumStr">
//	<xsd:simpleContent>
//		<xsd:extension base="xsd:string">
//			<xsd:attribute name="monsterId" type="StandardAAPJobGroupIdEnum" use="required"/>
//		</xsd:extension>
//	</xsd:simpleContent>
//</xsd:complexType>

//TODO: done
//<xsd:element name="MoveJob">
//	<xsd:annotation>
//		<xsd:documentation>Action of Move / Copy, and days until action happens.
//		Used to automatically move a job from an Intranet board to either the destinationBoardId or Monster (the default)</xsd:documentation>
//	</xsd:annotation>
//	<xsd:complexType>
//		<xsd:attribute name="moveAction" use="optional">
//			<xsd:simpleType>
//				<xsd:restriction base="xsd:string">
//					<xsd:enumeration value="move"/>
//					<xsd:enumeration value="copy"/>
//				</xsd:restriction>
//			</xsd:simpleType>
//		</xsd:attribute>
//		<xsd:attribute name="moveDays" type="xsd:int" use="optional"/>
//		<xsd:attribute name="destinationBoardId" type="xsd:int" use="optional"/>
//	</xsd:complexType>
//</xsd:element>