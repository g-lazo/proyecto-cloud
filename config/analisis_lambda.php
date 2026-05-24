<?php
require_once __DIR__ . '/vendor/autoload.php';

use Aws\Lambda\LambdaClient;
use Aws\Exception\AwsException;

function realizarAnalisisConLambda($payload) {
    // 1. Leer el nombre de la función que guardamos en Docker
    $lambdaFunctionName = getenv('LAMBDA_FUNCTION_NAME');
    
    if (!$lambdaFunctionName) {
        // Si por algo no encuentra la variable, avisa que falta configurar Docker
        return ['error' => 'No se configuró la variable LAMBDA_FUNCTION_NAME en Docker.'];
    }

    try {
        // 2. Conectarse a AWS usando las llaves de Docker
        $lambdaClient = new LambdaClient([
            'version' => 'latest',
            'region'  => getenv('AWS_REGION') ?: 'us-east-1',
            'credentials' => [
                'key'    => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);

        // 3. Enviar los datos a tu función de Python "MiFuncionAnalisisFinanciero"
        $result = $lambdaClient->invoke([
            'FunctionName' => $lambdaFunctionName,
            'Payload' => json_encode($payload),
        ]);

        // 4. Leer lo que nos respondió AWS
        $responseBody = $result->get('Payload')->getContents();
        $responseData = json_decode($responseBody, true);

        // 5. Como tu Python mete todo en un 'body', lo desempaquetamos
        if (isset($responseData['body'])) {
            return json_decode($responseData['body'], true);
        }

        return $responseData;

    } catch (AwsException $e) {
        // Si algo falla con AWS, devolvemos el error para saber qué pasó
        return ['error' => 'Error en AWS Lambda: ' . $e->getMessage()];
    }
}
