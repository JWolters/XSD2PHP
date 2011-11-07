<?php
/**
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

	public function initialize() {

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

//TODO:
//<xsd:complexType name="StandardAAPJobGroupEnumStr">
//	<xsd:simpleContent>
//		<xsd:extension base="xsd:string">
//			<xsd:attribute name="monsterId" type="StandardAAPJobGroupIdEnum" use="required"/>
//		</xsd:extension>
//	</xsd:simpleContent>
//</xsd:complexType>

//TODO:
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