<?php
namespace Objects\Event\FlowComponents;

use Objects\Event\TemplateReplacer;


class Webhook {
    /**
     * send a http request to a url.
     * $data contains:
     * - url
     * - method
     * - content
     * - contenttype
     * - headers JSON-encoded array of {key: "", value:""}
     */
    public static function execute($data, $parameters) {
        $client = new \GuzzleHttp\Client();

        $url         = TemplateReplacer::replaceAll($data['url'], $parameters);
        $method      = TemplateReplacer::replaceAll($data['method'], $parameters);
        $content     = TemplateReplacer::replaceAll($data['content'], $parameters);
        $contenttype = TemplateReplacer::replaceAll($data['contenttype'], $parameters);
        $headers     = json_decode($data['headers'] ?? "[]", true);

        $_headers = [
            "Content-Type" => $contenttype
        ];
        foreach ($headers as $header) {
            if ($header['key'] !== "")
                $_headers[TemplateReplacer::replaceAll($header['key'], $parameters)] =
                    TemplateReplacer::replaceAll($header['value'], $parameters);
        }

        $e = match ($contenttype) {
            "text/plain" => [
                'headers' => [
                    'Content-Type' => $contenttype,
                    ...$_headers
                ], 'body' => $content],
            default => [
                'headers' => [
                    'Content-Type' => $contenttype,
                    ...$_headers
                ],
                'json'    => json_decode($content)]
        };

        return $client->request($method, $url, [
            'headers' => [
                'Content-Type' => $contenttype,
                ...$_headers
            ],
            ...$e,
        ]);

    }

}