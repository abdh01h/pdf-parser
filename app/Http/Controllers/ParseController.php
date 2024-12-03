<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;

class ParseController extends Controller
{
    public function ParsePDFIndex()
    {
        $page_title = 'PDF Parser';

        return view('parse-pdf-index', [
            'page_title' => $page_title,
        ]);
    }

    public function ParsePDFSubmit(Request $request)
    {
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:2048',
        ]);

        $pdfFile = $request->file('pdf');

        // $this->PdfCo($pdfFile);

        $pdfArray = $this->PdfToArray($pdfFile);


        // Call OpenAI API to structure the array into organized JSON
        $structuredJson = $this->ArrayToJson($pdfArray);
        $structuredJson = preg_replace('/^```json|```$/', '', $structuredJson);
        $structuredJson = json_decode(trim($structuredJson), true);

        // Convert flattened JSON to CSV
        $csvData = $this->JsonToCsv($structuredJson);

        $fileName = pathinfo($pdfFile->getClientOriginalName(), PATHINFO_FILENAME) . '.csv';

        return response()->streamDownload(function () use ($csvData) {
            echo $csvData;
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ]);
    }

    public function ParsePDFSubmit2(Request $request)
    {
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:2048',
        ]);

        $pdfFile = $request->file('pdf');

        $this->PdfCo($pdfFile);
    }

    protected function PdfCo($pdfFile)
    {
        $apiKey = env('PDFCO_API_KEY');

        // Create URL
        $url = "https://api.pdf.co/v1/file/upload/get-presigned-url" .
        "?name=" . urlencode($pdfFile->getClientOriginalName()) .
        "&contenttype=application/octet-stream";

        // Create request
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("x-api-key: " . $apiKey));
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        // Execute request
        $result = curl_exec($curl);

        if (curl_errno($curl) == 0)
        {
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($status_code == 200)
        {
            $json = json_decode($result, true);

            $uploadFileUrl = $json["presignedUrl"];
            $uploadedFileUrl = $json["url"];

            $localFile = $pdfFile->getRealPath();
            $fileHandle = fopen($localFile, "r");

            curl_setopt($curl, CURLOPT_URL, $uploadFileUrl);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("content-type: application/octet-stream"));
            curl_setopt($curl, CURLOPT_PUT, true);
            curl_setopt($curl, CURLOPT_INFILE, $fileHandle);
            curl_setopt($curl, CURLOPT_INFILESIZE, filesize($localFile));

            // Execute request
            curl_exec($curl);

            fclose($fileHandle);

            if (curl_errno($curl) == 0)
            {
                $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if ($status_code == 200)
                {
                    $this->ExtractCSV($apiKey, $uploadedFileUrl);
                }
                else
                {
                    // Display request error
                    echo "<p>Status code: " . $status_code . "</p>";
                    echo "<p>" . $result . "</p>";
                }
            }
            else
            {
                // Display CURL error
                echo "Error: " . curl_error($curl);
            }
        }
        else
        {
            // Display service reported error
            echo "<p>Status code: " . $status_code . "</p>";
            echo "<p>" . $result . "</p>";
        }

        curl_close($curl);
        }
        else
        {
            // Display CURL error
            echo "Error: " . curl_error($curl);
        }
    }

    protected function ExtractCSV($apiKey, $uploadedFileUrl)
    {
        $url = "https://api.pdf.co/v1/pdf/convert/to/csv";

        // Prepare requests params
        $parameters = array();
        $parameters["url"] = $uploadedFileUrl;

        $data = json_encode($parameters);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("x-api-key: " . $apiKey, "Content-type: application/json"));
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);

        if (curl_errno($curl) == 0)
        {
            $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($status_code == 200)
            {
                $json = json_decode($result, true);

                if (!isset($json["error"]) || $json["error"] == false)
                {
                    $resultFileUrl = $json["url"];

                    echo "<div><h2>Conversion Result:</h2><a href='" . $resultFileUrl . "' target='_blank'>" . $resultFileUrl . "</a></div>";
                }
                else
                {
                    echo "<p>Error: " . $json["message"] . "</p>";
                }
            }
            else
            {
                echo "<p>Status code: " . $status_code . "</p>";
                echo "<p>" . $result . "</p>";
            }
        }
        else
        {
            echo "Error: " . curl_error($curl);
        }

        curl_close($curl);
    }

    protected function PdfToArray($file)
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($file->getPathname());
        $text = $pdf->getText();

        $lines = explode(" ", $text);
        $dataArray = [];
        foreach ($lines as $line) {
            $dataArray[] = array_map('trim', preg_split('/\s+/', $line));
        }

        return $dataArray;
    }

    protected function ArrayToJson($array)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a JSON structuring assistant for invoices to be converted to CSV later.'],
                ['role' => 'user', 'content' => 'Convert this nested invoice data into a flat, organized JSON format so it can be converted to CSV. (Only Json result)' . json_encode($array)]
            ],
            'temperature' => 0.2,
        ]);

        $responseData = $response->json();
        // dd($responseData);
        if (isset($responseData['choices'])) {
            return $responseData['choices'][0]['message']['content'];
        } else {
            dd($responseData);
        }
    }

    protected function JsonToCsv($array)
    {
        $flattenedData = [];
        $this->FlattenArray($array, $flattenedData);

        // Generate CSV string
        $csvData = '';
        $headers = array_keys($flattenedData[0]);
        $csvData .= implode(',', $headers) . "\n";

        foreach ($flattenedData as $row) {
            $csvData .= implode(',', array_map(function($value) {
                $escapedValue = str_replace('"', '""', $value);
                return '"' . $escapedValue . '"';
            }, $row)) . "\n";
        }

        return $csvData;
    }

    protected function FlattenArray($array, &$output, $prefix = '')
    {
        static $row = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}_{$key}" : $key;
            if (is_array($value)) {
                $this->FlattenArray($value, $output, $newKey);
            } else {
                $row[$newKey] = $value;
            }
        }
        if (!empty($row)) {
            $output[] = $row;
            $row = [];
        }

    }

}
