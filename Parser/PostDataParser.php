<?php
namespace PHPPM\Parser;

/**
 * @author Vital Leshchyk <vitalleshchyk@gmail.com>
 */
class PostDataParser 
{
    /**
     * @param \React\Http\Request $request
     * @param string $data
     * @return array
     */
    public function parse(\React\Http\Request $request, $data)
    {
        $headers = $request->getHeaders();
        $contentType = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';
        $a = explode(';', $contentType);
        $contentType = $a[0];
        switch ($contentType) {
            case 'text/xml':
            case 'application/xml':
                $result = ['xmlString' => $data]; //raw xml string
                break;
            case 'application/json':
                $result = json_decode($data, true);
                break;
            default:
                parse_str($data, $result);
        }

        return $result;
    }
}
