<?php
/**
 * pam_mount config file parser/generator.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @copyright 2016 Peter Große
 * @lincense  http://www.apache.org/licenses/LICENSE-2.0  Apache License 2.0
 */
namespace PamMount;

class Config
{
    private $default = [
        'volume_attributes' => ['noroot' => 0]
    ];

    private $fuse_fstypes = ['bindfs'];

    private $xml = null;

    public function __construct($filename = null)
    {
        if(!empty($filename)) {
            $this->loadFile($filename);
        }
    }

    public function loadFile($filename)
    {
        if(!file_exists($filename)) {
            return false;
        }

        $content = file_get_contents($filename);

        return $this->loadString($content);
    }

    public function loadString($content)
    {
        $this->xml = simplexml_load_string($content);

        return true;
    }

    public function getVolumes()
    {
        if(empty($this->xml->volume)) {
            return false;
        }

        $mountpoints = [];
        foreach ($this->xml->volume as $volume) {
            $mountpoints[] = self::parseVolume($volume);
        }

        return $mountpoints;
    }

    public function addUser($volume, $username)
    {
        if(empty($this->xml->volume)) {
            return false;
        }

        $volume_element = $this->findVolume($volume, true);

        // add user
        $user_list = (empty($volume_element->or)) ? $volume_element->addChild('or') : $volume_element->or;
        $user_list->addChild('user',$username);

        return true;
    }

    public function removeUser($volume, $username)
    {
        if(empty($this->xml->volume)) {
            return false;
        }

        // find volume
        $volume_element = $this->findVolume($volume);

        if($volume_element === null || empty($volume_element->or)) {
            return false;
        }

        // remove user
        $found = false;
        foreach ($volume_element->or->user as $user) {
            if($user[0] == $username) {
                $found = true;
                unset($user[0]);
                break;
            }
        }

        return $found;
    }

    public function toString()
    {
        if($this->xml instanceof \SimpleXMLElement) {
            $domxml = new \DOMDocument('1.0');
            $domxml->preserveWhiteSpace = false;
            $domxml->formatOutput = true;
            /* @var $xml \SimpleXMLElement */
            $domxml->loadXML($this->xml->asXML());
            return $domxml->saveXML();
        }

        return false;
    }

    private function findVolume($volume, $create_if_not_found = false)
    {
        $volume_element = null;
        foreach ($this->xml->volume as $volume_element_candidate) {
            $volume_parsed = self::parseVolume($volume_element_candidate);

            if ($volume_parsed['path'] == $volume['path'] &&
                $volume_parsed['mountpoint'] == $volume['mountpoint'] &&
                (isset($volume['rw']) && isset($volume_parsed['rw']) || isset($volume['ro']) && isset($volume_parsed['ro']) ) ) {
                    $volume_element = $volume_element_candidate;
                    break;
            }
        }

        if($volume_element === null && $create_if_not_found) {
            // add volume
            $volume_element = $this->xml->addChild('volume');

            // set defaults
            foreach ($this->default['volume_attributes'] as $attr => $value) {
                $volume_element[$attr] = $value;
            }
            $volume_element['path'] = $volume['path'];
            $volume_element['mountpoint'] = $volume['mountpoint'];

            // handle fuse filesystems
            if(in_array($volume['fstype'], $this->fuse_fstypes)) {
                $volume_element['fstype'] = 'fuse';
                $volume_element['path'] = $volume['fstype'] . '#' . $volume['path'];
            }
            // rw is default, so only add "options" attribute for read-only permission
            if(isset($volume['ro'])) {
                $volume_element['options'] = 'ro';
            }
        }

        return $volume_element;
    }

    private static function parseVolume(\SimpleXMLElement $volume)
    {
        $volume_parsed = [
            'path' => (string)$volume['path'][0],
            'mountpoint' => (string)$volume['mountpoint'][0],
            'fstype' => isset($volume['fstype']) ? (string)$volume['fstype'][0] : 'bind',
        ];

        // parse permissions and whitelist
        $permission = (isset($volume['options']) && strpos($volume['options'][0], 'ro') !== false) ? 'ro' : 'rw';
        $volume_parsed[$permission] = ($volume->or->count()) ? array_values((array)$volume->or->user) : ['__ALL__'];

        // parse path: handle fuse filesystems and extract actual filesystem name
        $hash_pos = strpos($volume_parsed['path'], '#');
        if($hash_pos !== false) {
            $volume_parsed['fstype'] = substr($volume_parsed['path'], 0, $hash_pos);
            $volume_parsed['path'] = substr($volume_parsed['path'], $hash_pos + 1);
        }
        return $volume_parsed;
    }

}