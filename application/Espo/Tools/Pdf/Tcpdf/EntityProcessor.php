<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Tools\Pdf\Tcpdf;

use Espo\Core\{
    Exceptions\Error,
    Utils\Config,
    Htmlizer\Htmlizer as Htmlizer,
    Htmlizer\Factory as HtmlizerFactory,
    Pdf\Tcpdf,
};

use Espo\{
    ORM\Entity,
    Tools\Pdf\Template,
    Tools\Pdf\Data,
};

class EntityProcessor
{
    protected $fontFace = 'freesans';

    protected $fontSize = 12;

    protected $config;
    protected $htmlizerFactory;

    public function __construct(Config $config, HtmlizerFactory $htmlizerFactory)
    {
        $this->config = $config;
        $this->htmlizerFactory = $htmlizerFactory;
    }

    public function process(Tcpdf $pdf, Template $template, Entity $entity, Data $data)
    {
        $additionalData = $data->getAdditionalTemplateData();

        $htmlizer = $this->htmlizerFactory->create();

        $fontFace = $this->config->get('pdfFontFace', $this->fontFace);

        if ($template->getFontFace()) {
            $fontFace = $template->getFontFace();
        }

        $pdf->setFont($fontFace, '', $this->fontSize, '', true);

        $pdf->setAutoPageBreak(true, $template->getBottomMargin());

        $pdf->setMargins(
            $template->getLeftMargin(),
            $template->getTopMargin(),
            $template->getRightMargin()
        );

        if ($template->hasFooter()) {
            $htmlFooter = $this->render($htmlizer, $entity, $template->getFooter(), $additionalData);

            $pdf->setFooterFont([$fontFace, '', $this->fontSize]);
            $pdf->setFooterPosition($template->getFooterPosition());

            $pdf->setFooterHtml($htmlFooter);
        }
        else {
            $pdf->setPrintFooter(false);
        }

        $pageOrientation = $template->getPageOrientation();

        $pageFormat = $template->getPageFormat();

        if ($pageFormat === 'Custom') {
            $pageFormat = [
                $template->getPageWidth(),
                $template->getPageHeight(),
            ];
        }

        $pageOrientationCode = 'P';

        if ($pageOrientation === 'Landscape') {
            $pageOrientationCode = 'L';
        }

        $htmlHeader = '';

        if ($template->getHeader() !== '') {
            $htmlHeader = $this->render($htmlizer, $entity, $template->getHeader(), $additionalData);
        }

        if ($template->hasHeader()) {
            $pdf->setHeaderFont([$fontFace, '', $this->fontSize]);
            $pdf->setHeaderPosition($template->getHeaderPosition());

            $pdf->setHeaderHtml($htmlHeader);

            $pdf->addPage($pageOrientationCode, $pageFormat);
        }
        else {
            $pdf->addPage($pageOrientationCode, $pageFormat);

            $pdf->setPrintHeader(false);

            $pdf->writeHTML($htmlHeader, true, false, true, false, '');
        }

        $htmlBody = $this->render($htmlizer, $entity, $template->getBody(), $additionalData);

        $pdf->writeHTML($htmlBody, true, false, true, false, '');
    }

    protected function render(Htmlizer $htmlizer, Entity $entity, string $template, array $additionalData) : string
    {
        $html = $htmlizer->render(
            $entity,
            $template,
            null,
            $additionalData
        );

        $html = preg_replace_callback(
            '/<barcodeimage data="([^"]+)"\/>/',
            function ($matches) {
                $dataString = $matches[1];

                $data = json_decode(urldecode($dataString), true);

                return $this->composeBarcodeTag($data);
            },
            $html
        );

        return $html;
    }

    protected function composeBarcodeTag(array $data) : string
    {
        $value = $data['value'] ?? null;

        $codeType = $data['type'] ?? 'CODE128';

        $typeMap = [
            "CODE128" => 'C128',
            "CODE128A" => 'C128A',
            "CODE128B" => 'C128B',
            "CODE128C" => 'C128C',
            "EAN13" => 'EAN13',
            "EAN8" => 'EAN8',
            "EAN5" => 'EAN5',
            "EAN2" => 'EAN2',
            "UPC" => 'UPCA',
            "UPCE" => 'UPCE',
            "ITF14" => 'I25',
            "pharmacode" => 'PHARMA',
            "QRcode" => 'QRCODE,H',
        ];

        if ($codeType === 'QRcode') {
            $function = 'write2DBarcode';

            $params = [
                $value,
                $typeMap[$codeType] ?? null,
                '', '',
                $data['width'] ?? 40,
                $data['height'] ?? 40,
                [
                    'border' => false,
                    'vpadding' => $data['padding'] ?? 2,
                    'hpadding' => $data['padding'] ?? 2,
                    'fgcolor' => $data['color'] ?? [0,0,0],
                    'bgcolor' => $data['bgcolor'] ?? false,
                    'module_width' => 1,
                    'module_height' => 1,
                ],
                'N',
            ];
        } else {
            $function = 'write1DBarcode';

            $params = [
                $value,
                $typeMap[$codeType] ?? null,
                '', '',
                $data['width'] ?? 60,
                $data['height'] ?? 30,
                0.4,
                [
                    'position' => 'S',
                    'border' => false,
                    'padding' => $data['padding'] ?? 0,
                    'fgcolor' => $data['color'] ?? [0,0,0],
                    'bgcolor' => $data['bgcolor'] ?? [255,255,255],
                    'text' => $data['text'] ?? true,
                    'font' => 'helvetica',
                    'fontsize' => $data['fontsize'] ?? 14,
                    'stretchtext' => 4,
                ],
                'N',
            ];
        }

        $paramsString = urlencode(json_encode($params));

        return "<tcpdf method=\"{$function}\" params=\"{$paramsString}\" />";
    }
}
