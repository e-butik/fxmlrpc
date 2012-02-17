<?php
namespace FXMLRPC\Parser;

use XMLReader;
use RuntimeException;
use DateTime;
use DateTimeZone;

class XMLReaderParser implements ParserInterface
{
    public function __construct()
    {
        if (!extension_loaded('xmlreader')) {
            throw new RuntimeException('PHP extension ext/xmlreader missing');
        }
    }

    public function parse($xmlString)
    {
        libxml_use_internal_errors(true);

        $xml = new XMLReader();
        $xml->xml(
            $xmlString,
            'UTF-8',
            LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS
        );
        $xml->setParserProperty(XMLReader::VALIDATE, false);
        $xml->setParserProperty(XMLReader::LOADDTD, false);

        $aggregates = array();
        $depth = 0;
        $nextElements = array('methodResponse' => 1);
        while ($xml->read()) {
            $nodeType = $xml->nodeType;

            if (!isset($nextElements['#text']) && $nodeType === XMLReader::SIGNIFICANT_WHITESPACE) {
                continue;
            }

            $tagName = (string) $xml->name;


            if (!isset($nextElements[$tagName])) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid XML. Expected one of "%s", got "%s" on depth %d (context: "%s")',
                        join('", "', array_keys($nextElements)),
                        $tagName,
                        $xml->depth,
                        $xml->readOuterXml()
                    )
                );
            }

            switch ($nodeType) {
                case XMLReader::ELEMENT:
                    switch ($tagName) {
                        case 'methodResponse':
                            $nextElements = array('params' => 1, 'fault' => 1);
                            break;

                        case 'fault':
                            $nextElements = array('value' => 1);
                            break;

                        case 'params':
                            $nextElements = array('param' => 1);
                            $aggregates[$depth] = array();
                            break;

                        case 'param':
                            $nextElements = array('value' => 1);
                            break;

                        case 'array':
                            $nextElements = array('data' => 1);
                            ++$depth;
                            $aggregates[$depth] = array();
                            break;

                        case 'data':
                            $nextElements = array('value' => 1, 'data' => 1);
                            break;

                        case 'struct':
                            $nextElements = array('member' => 1);
                            ++$depth;
                            $aggregates[$depth] = array();
                            break;

                        case 'member':
                            $nextElements = array('name' => 1, 'value' => 1);
                            ++$depth;
                            $aggregates[$depth] = array();
                            break;

                        case 'name':
                            $nextElements = array('#text' => 1);
                            $type = 'name';
                            break;

                        case 'value':
                            $nextElements = array(
                                'string'           => 1,
                                'array'            => 1,
                                'struct'           => 1,
                                'int'              => 1,
                                'i4'               => 1,
                                'boolean'          => 1,
                                'double'           => 1,
                                'dateTime.iso8601' => 1,
                                'base64'           => 1,
                            );
                            break;

                        case 'base64':
                        case 'string':
                            $nextElements = array('#text' => 1, $tagName => 1, 'value' => 1);
                            $type = $tagName;
                            $aggregates[$depth + 1] = '';
                            break;

                        case 'int':
                        case 'i4':
                            $nextElements = array('#text' => 1, $tagName => 1, 'value' => 1);
                            $type = $tagName;
                            $aggregates[$depth + 1] = 0;
                            break;

                        case 'boolean':
                            $nextElements = array('#text' => 1, $tagName => 1, 'value' => 1);
                            $type = $tagName;
                            $aggregates[$depth + 1] = false;
                            break;

                        case 'double':
                            $nextElements = array('#text' => 1, $tagName => 1, 'value' => 1);
                            $type = $tagName;
                            $aggregates[$depth + 1] = 0.0;
                            break;

                        case 'dateTime.iso8601':
                            $nextElements = array('#text' => 1, $tagName => 1, 'value' => 1);
                            $type = $tagName;
                            $aggregates[$depth + 1] = '';
                            break;

                        default:
                            throw new RuntimeException(
                                sprintf(
                                    'Invalid tag <%s> found',
                                    $tagName
                                )
                            );
                    }
                    break;

                case XMLReader::END_ELEMENT:
                    switch ($tagName) {
                        case 'param':
                        case 'fault':
                            break 3;
                            break;

                        case 'value':
                            $nextElements = array(
                                'param'  => 1,
                                'value'  => 1,
                                'data'   => 1,
                                'member' => 1,
                                'name'   => 1,
                                'int'    => 1,
                                'i4'     => 1,
                                'base64' => 1,
                                'fault'  => 1,
                            );
                            $aggregates[$depth][] = $aggregates[$depth + 1];
                            break;

                        case 'string':
                        case 'int':
                        case 'i4':
                        case 'boolean':
                        case 'double':
                        case 'dateTime.iso8601':
                        case 'base64':
                            $nextElements = array('value' => 1);
                            break;

                        case 'data':
                            $nextElements = array('array' => 1);
                            break;

                        case 'array':
                            $nextElements = array('value' => 1);
                            --$depth;
                            break;

                        case 'name':
                            $nextElements = array('value' => 1, 'member' => 1);
                            $aggregates[$depth]['name'] = $aggregates[$depth + 1];
                            break;

                        case 'member':
                            $nextElements = array('struct' => 1, 'member' => 1);
                            $aggregates[$depth - 1][$aggregates[$depth]['name']] = $aggregates[$depth][0];
                            unset($aggregates[$depth], $aggregates[$depth + 1]);
                            --$depth;
                            break;

                        case 'struct':
                            $nextElements = array('value' => 1);
                            --$depth;
                            break;

                        default:
                            throw new RuntimeException(
                                sprintf(
                                    'Invalid tag </%s> found',
                                    $tagName
                                )
                            );
                    }
                    break;

                case XMLReader::TEXT:
                case XMLReader::SIGNIFICANT_WHITESPACE:
                    switch ($type) {
                        case 'int':
                        case 'i4':
                            $value = (int) $xml->value;
                            break;

                        case 'boolean':
                            $value = $xml->value === '1';
                            break;

                        case 'double':
                            $value = (double) $xml->value;
                            break;

                        case 'dateTime.iso8601':
                            $value = DateTime::createFromFormat('Ymd\TH:i:s', $xml->value, new DateTimeZone('UTC'));
                            break;

                        case 'base64':
                            $value = base64_decode($xml->value);
                            break;

                        default:
                            $value = $xml->value;
                            break;
                    }

                    $aggregates[$depth + 1] = $value;
                    $nextElements = array($type => 1);
                    break;
            }
        }

        return isset($aggregates[0][0]) ? $aggregates[0][0] : null;
    }
}