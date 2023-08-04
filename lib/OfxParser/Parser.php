<?php

namespace OfxParser;

/**
 * An OFX parser library
 *
 * Heavily refactored from Guillaume Bailleul's grimfor/ofxparser
 *
 * @author Guillaume BAILLEUL <contact@guillaume-bailleul.fr>
 * @author James Titcumb <hello@jamestitcumb.com>
 * @author Oliver Lowe <mrtriangle@gmail.com>
 */
class Parser
{

	/**
	 * Load an OFX file into this parser by way of a filename
	 *
	 * @param string $ofxFile A path or an url that can be loaded with file_get_contents
	 * @return  Ofx
	 * @throws \InvalidArgumentException
	 */
	public function loadFromFile($ofxFile)
	{
		$url = strpos($ofxFile, 'http');
		if (file_exists($ofxFile) || $url)
		{
			return $this->loadFromString(file_get_contents($ofxFile));
		}
		else
		{
			throw new \InvalidArgumentException("File '{$ofxFile}' could not be found");
		}
	}

	/**
	 * Load an OFX by directly using the text content
	 *
	 * @param string $ofxContent
	 * @return  Ofx
	 * @throws \Exception
	 */
	public function loadFromString($ofxContent)
	{
		$ofxEncoding = mb_detect_encoding($ofxContent);
		$ofxContent = mb_convert_encoding($ofxContent, "UTF-8", $ofxEncoding);

		$sgmlStart = stripos($ofxContent, '<OFX>');
		$ofxHeader = trim(substr($ofxContent, 0, $sgmlStart));
		$ofxSgml = trim(substr($ofxContent, $sgmlStart));

		// IF THERE IS A CHARACTER & WHICH CAUSES THE FILE TO BE INVALID, IT REPLACES
      $ofxSgml = str_replace('&', '', $ofxSgml);

		// WHEN TYPE IS EMPTY Wiil BE FILL WITH OTHER
		$enR = str_replace("<TRNTYPE> ", "<TRNTYPE>OTHER", $ofxSgml);
		
		$ofxXml = $this->convertSgmlToXml($enR);
		$xml = $this->xmlLoadString($ofxXml);

		return new \OfxParser\Ofx($xml);
	}

	/**
	 * Load an XML string without PHP errors - throws exception instead
	 *
	 * @param string $xmlString
	 * @throws \Exception
	 * @return \SimpleXMLElement
	 */
	private function xmlLoadString($xmlString)
	{
		libxml_clear_errors();
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($xmlString);

		if ($errors = libxml_get_errors())
		{
			throw new \Exception("Failed to parse OFX: " . var_export($errors, true));
		}

		return $xml;
	}

	/**
	 * Detect any unclosed XML tags - if they exist, close them
	 *
	 * @param string $line
	 * @return $line
	 */
	private function closeUnclosedXmlTags($line)
	{
		// Matches: <SOMETHING>blah
		// Does not match: <SOMETHING>
		// Does not match: <SOMETHING>blah</SOMETHING>
		if (preg_match("/<([A-Za-z0-9.]+)>([\wà-úÀ-Ú0-9\.\-\_\+\, ;:\[\]\'\&\/\\\*\(\)\+\{\}\!\£\$\?=@€£#%±§~`]+)$/", trim($line), $matches))
		{
			return "<{$matches[1]}>{$matches[2]}</{$matches[1]}>";
		}
		return $line;
	}

	/**
	 * Convert an SGML to an XML string
	 *
	 * @param string $sgml
	 * @return string
	 */
	private function convertSgmlToXml($sgml)
	{
		$sgml = str_replace("\r\n", "\n", $sgml);
		$sgml = str_replace("\r", "\n", $sgml);

		$lines = explode("\n", $sgml);

		$xml = "";
		foreach ($lines as $line)
		{
			$xml .= trim($this->closeUnclosedXmlTags($line)) . "\n";
		}

		return trim($xml);
	}
}