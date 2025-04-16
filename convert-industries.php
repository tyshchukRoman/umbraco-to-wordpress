<?php
class ConvertIndustries
{
    public string $mediaDir = 'Media';
    public string $inputDir = 'umbraco_exports';
    public string $outputDir = 'wordpress_imports';

    public array $languageMap = [
        'en-gb' => 'uk',
        'da-dk' => 'dk', 
        'de-de' => 'de',
        'en-au' => 'au',
        'en-nz' => 'nz'
    ];

    public string $defaultLanguage = 'en-gb';

    public array $allFiles = [];
    public array $allMedia = [];
    public array $outputFiles = [];
    public array $images = [];

    public function __construct() {
        $this->allFiles = glob("{$this->inputDir}/*");
        $this->loadImages();
        $this->initializeFiles();
        $this->processIndustries();
    }

    /*
     * Load $images array
     */
    public function loadImages() 
    {
        $this->allMedia = glob("{$this->mediaDir}/*");
        foreach ($this->allMedia as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) continue;
            
            if ((string)$xml->Info->ContentType !== 'Image') continue;
        
            $imageId = str_replace('-', '', (string)$xml['Key']);
            $imageSrcArray = explode('/', (string)$xml->Properties->umbracoFile->Value);
            $imageName = array_pop($imageSrcArray);
            $imageSrc = 'https://smartparking.codelibry.dev/wp-content/uploads/2025/04/' . $imageName;
            $imageSrc = str_replace('"}', '', $imageSrc);
            $this->images[$imageId] = $imageSrc;
        }
    }

    /*
     * Initialize Files
     */
    public function initializeFiles() 
    {
        foreach ($this->languageMap as $umbracoLang => $wpLang) {
            $filename = "industries-{$wpLang}.xml";
            $outputPath = "{$this->outputDir}/{$filename}";
            
            $fileHandle = fopen($outputPath, 'w');
            if ($fileHandle === false) {
                die("Error creating output file: {$outputPath}\n");
            }
            
            $this->outputFiles[$umbracoLang] = $fileHandle;
            fwrite($fileHandle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
            fwrite($fileHandle, '<wpml>' . PHP_EOL);
            fwrite($fileHandle, '  <posts>' . PHP_EOL);
        }
    }

    /*
     * Process Industry Files
     */
    public function processIndustries() 
    {
        $processedIds = [];
        $processedCount = 0;

        foreach ($this->allFiles as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                echo "Failed to load file: $file\n";
                continue;
            }

            // Only process industry content types
            if ((string)$xml->Info->ContentType !== 'industry') continue;

            $postId = (string)$xml['Key'];
            if (isset($processedIds[$postId])) continue;
            $processedIds[$postId] = true;

            // Process for each language
            foreach ($this->languageMap as $umbracoLang => $wpLang) {
                $output = $this->outputFiles[$umbracoLang];
                
                // Skip if not published for this language
                $published = $this->getPublishedStatus($xml->Info->Published, $umbracoLang);
                if (!$published) continue;

                // Get language-specific content
                $title = $this->getValueForLanguage($xml->Info->NodeName->Name, $umbracoLang);
                if (empty($title)) continue;

                $summary = $this->getValueForLanguage($xml->Properties->summary, $umbracoLang);
                $headerText = $this->getValueForLanguage($xml->Properties->headerText, $umbracoLang);
                $createDate = (string)$xml->Info->CreateDate;

                // Images
                $centralImageId = $this->getMediaKeyFromProperty($xml->Properties->centralImage, $umbracoLang);
                $iconImageId = $this->getMediaKeyFromProperty($xml->Properties->icon, $umbracoLang);

                // Complex fields
                $testimonialData = $this->getJsonField($xml->Properties->testimonial, $umbracoLang);
                $leftImageBlockData = $this->getJsonField($xml->Properties->leftImageWithContentBlock, $umbracoLang);
                $technologyData = $this->getJsonField($xml->Properties->technologySection, $umbracoLang);

                // Write post
                fwrite($output, '    <post>' . PHP_EOL);
                fwrite($output, '      <id>' . htmlspecialchars($postId, ENT_QUOTES, 'UTF-8') . '</id>' . PHP_EOL);
                fwrite($output, '      <title><![CDATA[' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ']]></title>' . PHP_EOL);
                fwrite($output, '      <post_type>industry</post_type>' . PHP_EOL);
                fwrite($output, '      <post_status>publish</post_status>' . PHP_EOL);
                fwrite($output, '      <post_date>' . $createDate . '</post_date>' . PHP_EOL);
                
                // Basic content
                if (!empty($headerText)) {
                    fwrite($output, '      <header_text><![CDATA[' . htmlspecialchars($headerText, ENT_QUOTES, 'UTF-8') . ']]></header_text>' . PHP_EOL);
                }
                if (!empty($summary)) {
                    fwrite($output, '      <post_content><![CDATA[' . $summary . ']]></post_content>' . PHP_EOL);
                }
                
                // Images
                if (!empty($centralImageId) && isset($this->images[$centralImageId])) {
                    fwrite($output, '      <central_image>' . $this->images[$centralImageId] . '</central_image>' . PHP_EOL);
                }
                if (!empty($iconImageId) && isset($this->images[$iconImageId])) {
                    fwrite($output, '      <icon_image>' . $this->images[$iconImageId] . '</icon_image>' . PHP_EOL);
                }
                
                // Left Image Block
                if (!empty($leftImageBlockData) && isset($leftImageBlockData[0])) {
                    $this->writeImageContentBlock($output, $leftImageBlockData[0]);
                }
                
                // Technology Section
                if (!empty($technologyData) && isset($technologyData[0])) {
                    $this->writeTechnologySection($output, $technologyData[0]);
                }
                
                // Testimonial
                if (!empty($testimonialData) && isset($testimonialData[0])) {
                    $this->writeTestimonial($output, $testimonialData[0]);
                }
                
                fwrite($output, '    </post>' . PHP_EOL);
                
                $processedCount++;
            }
        }

        // Close files
        foreach ($this->outputFiles as $fileHandle) {
            fwrite($fileHandle, '  </posts>' . PHP_EOL);
            fwrite($fileHandle, '</wpml>' . PHP_EOL);
            fclose($fileHandle);
        }

        echo "Conversion complete. Processed {$processedCount} industry pages.\n";
    }

    protected function writeImageContentBlock($output, $block) {
        fwrite($output, '      <acf>' . PHP_EOL);
        fwrite($output, '        <image_content_block>' . PHP_EOL);
        
        if (isset($block['headerText'])) {
            fwrite($output, '          <header><![CDATA[' . htmlspecialchars($block['headerText'], ENT_QUOTES, 'UTF-8') . ']]></header>' . PHP_EOL);
        }
        if (isset($block['summaryText'])) {
            fwrite($output, '          <summary><![CDATA[' . htmlspecialchars($block['summaryText'], ENT_QUOTES, 'UTF-8') . ']]></summary>' . PHP_EOL);
        }
        
        // Process images in the block
        $image1 = $this->getMediaKey($block['image1'] ?? '');
        $image2 = $this->getMediaKey($block['image2'] ?? '');
        if (!empty($image1) && isset($this->images[str_replace('-', '', $image1)])) {
            fwrite($output, '          <image1>' . $this->images[str_replace('-', '', $image1)] . '</image1>' . PHP_EOL);
        }
        if (!empty($image2) && isset($this->images[str_replace('-', '', $image2)])) {
            fwrite($output, '          <image2>' . $this->images[str_replace('-', '', $image2)] . '</image2>' . PHP_EOL);
        }
        
        fwrite($output, '        </image_content_block>' . PHP_EOL);
        fwrite($output, '      </acf>' . PHP_EOL);
    }

    protected function writeTechnologySection($output, $tech) {
        fwrite($output, '      <technology_section>' . PHP_EOL);
        if (isset($tech['title'])) {
            fwrite($output, '        <title><![CDATA[' . htmlspecialchars($tech['title'], ENT_QUOTES, 'UTF-8') . ']]></title>' . PHP_EOL);
        }
        
        if (isset($tech['systemCompnonents'])) {
            $components = json_decode($tech['systemCompnonents'], true);
            if ($components) {
                foreach ($components as $component) {
                    $icon = $this->getMediaKey($component['iconImage'] ?? '');
                    fwrite($output, '        <technology>' . PHP_EOL);
                    if (isset($component['title'])) {
                        fwrite($output, '          <name><![CDATA[' . htmlspecialchars($component['title'], ENT_QUOTES, 'UTF-8') . ']]></name>' . PHP_EOL);
                    }
                    if (isset($component['summary'])) {
                        fwrite($output, '          <description><![CDATA[' . htmlspecialchars($component['summary'], ENT_QUOTES, 'UTF-8') . ']]></description>' . PHP_EOL);
                    }
                    if (!empty($icon) && isset($this->images[str_replace('-', '', $icon)])) {
                        fwrite($output, '          <icon>' . $this->images[str_replace('-', '', $icon)] . '</icon>' . PHP_EOL);
                    }
                    fwrite($output, '        </technology>' . PHP_EOL);
                }
            }
        }
        fwrite($output, '      </technology_section>' . PHP_EOL);
    }

    protected function writeTestimonial($output, $testimonial) {
        fwrite($output, '      <testimonial>' . PHP_EOL);
        if (isset($testimonial['quote'])) {
            fwrite($output, '        <quote><![CDATA[' . htmlspecialchars($testimonial['quote'], ENT_QUOTES, 'UTF-8') . ']]></quote>' . PHP_EOL);
        }
        if (isset($testimonial['name'])) {
            fwrite($output, '        <author><![CDATA[' . htmlspecialchars($testimonial['name'], ENT_QUOTES, 'UTF-8') . ']]></author>' . PHP_EOL);
        }
        if (isset($testimonial['position'])) {
            fwrite($output, '        <position><![CDATA[' . htmlspecialchars($testimonial['position'], ENT_QUOTES, 'UTF-8') . ']]></position>' . PHP_EOL);
        }
        if (isset($testimonial['company'])) {
            fwrite($output, '        <company><![CDATA[' . htmlspecialchars($testimonial['company'], ENT_QUOTES, 'UTF-8') . ']]></company>' . PHP_EOL);
        }
        fwrite($output, '      </testimonial>' . PHP_EOL);
    }

    /*
     * Helper Functions
     */
    public function getPublishedStatus($publishedNode, $targetLanguage) {
        if ($publishedNode === null) return false;
        
        // Check if published for specific language
        foreach ($publishedNode->Published as $published) {
            if ((string)$published['Culture'] === $targetLanguage) {
                return (string)$published === 'true';
            }
        }
        
        // Fallback to default published status
        return (string)$publishedNode['Default'] === 'true';
    }

    public function getValueForLanguage($node, $targetLanguage) {
        if ($node === null) return '';
        
        // If it's a simple value without language variations
        if (!isset($node['Culture'])) {
            return (string)$node;
        }
        
        // Look for exact language match
        foreach ($node as $value) {
            if ((string)$value['Culture'] === $targetLanguage) {
                return (string)$value;
            }
        }
        
        // If no match found, return empty string
        return '';
    }

    public function getMediaKeyFromProperty($property, $targetLanguage) {
        if ($property === null) return '';
        
        $value = $this->getValueForLanguage($property, $targetLanguage);
        if (empty($value)) return '';
        
        $mediaKey = $this->getMediaKey($value);
        return str_replace('-', '', $mediaKey);
    }

    public function getJsonField($property, $targetLanguage) {
        if ($property === null) return null;
        
        $value = $this->getValueForLanguage($property, $targetLanguage);
        if (empty($value)) return null;
        
        return json_decode($value, true);
    }

    public function getMediaKey($jsonString) {
        if (empty($jsonString)) return '';
        
        $data = json_decode($jsonString, true);
        if ($data === null) return '';
        
        if (isset($data[0]['mediaKey'])) {
            return $data[0]['mediaKey'];
        }
        
        return '';
    }
}

$converter = new ConvertIndustries();
