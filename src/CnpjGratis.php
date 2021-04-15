<?php

namespace JansenFelipe\CnpjGratis;

use Exception;
use JansenFelipe\Utils\Utils as Utils;
use Symfony\Component\DomCrawler\Crawler;

class CnpjGratis {
    /**
     * Metodo para capturar o captcha e cookie para enviar no metodo de consulta
     *
     * @throws Exception
     * @return array Retorna Cookie e CaptchaBase64
     */
    public static function getParams(){
        $data = self::request('http://servicos.receita.fazenda.gov.br/Servicos/cnpjreva/Cnpjreva_Solicitacao.asp');
        $cookie = $data['headers']['Set-Cookie'];
        $image = self::request('http://servicos.receita.fazenda.gov.br/Servicos/cnpjreva/captcha/gerarCaptcha.asp', [], [
            "Pragma: no-cache",
            "Origin: http://www.receita.fazenda.gov.br",
            "Host: servicos.receita.fazenda.gov.br",
            "User-Agent: Mozilla/5.0 (Windows NT 6.1; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: pt-BR,pt;q=0.8,en-US;q=0.5,en;q=0.3",
            "Accept-Encoding: gzip, deflate",
            "Referer: http://servicos.receita.fazenda.gov.br/Servicos/cnpjreva/Cnpjreva_Solicitacao.asp",
            "Cookie: flag=1; $cookie",
            "Connection: keep-alive"
        ]);
        if(@imagecreatefromstring($image['response'])==false){
            throw new Exception('Não foi possível capturar o captcha');
        }
        return array(
            'cookie' => $cookie,
            'captchaBase64' => base64_encode($image['response'])
        );
    }

    /**
     * Metodo para realizar a consulta
     *
     * @param  string $cnpj CNPJ
     * @param  string $captchaSolved CAPTCHA
     * @param  string $cookie COOKIE
     * @throws Exception
     * @return array  Dados da empresa
     */
    public static function consulta($cnpj, $captchaSolved, $cookie){
        $result = array();
        if(!Utils::isCnpj($cnpj)){
            throw new Exception('O CNPJ informado não é válido');
        }
        $headers = [
            "Host: servicos.receita.fazenda.gov.br",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
            "Accept-Language: pt-BR,pt;q=0.9,en;q=0.8",
            "Accept-Encoding: gzip, deflate",
            "Referer: http://servicos.receita.fazenda.gov.br/Servicos/cnpjreva/Cnpjreva_Solicitacao_CS.asp",
            "Cookie: $cookie",
            "Connection: keep-alive"
        ];
        $params = [
            'origem' => 'comprovante',
            'cnpj' => Utils::unmask($cnpj),
            'txtTexto_captcha_serpro_gov_br' => $captchaSolved,
            'search_type' => 'cnpj'
        ];
        $data = self::request('http://servicos.receita.fazenda.gov.br/Servicos/cnpjreva/valida.asp', $params, $headers);
        $crawler = new Crawler($data['response']);
        if(strpos($crawler->html(), '<b>Erro na Consulta</b>') !== false){
            throw new Exception('Erro ao consultar. Confira se você digitou corretamente os caracteres fornecidos na imagem.', 98);
        }elseif($crawler->filter('body > table:nth-child(3) font:nth-child(1)')->count() > 0){
            throw new Exception('Erro ao consultar. O CNPJ informado não existe no cadastro.', 99);
        }else{
            $td = $crawler->filter('#principal > table:nth-child(1)');
            foreach ($td->filter('td') as $td){
                $td = new Crawler($td);
                if($td->filter('font:nth-child(1)')->count() > 0){
                    $key = trim(strip_tags(preg_replace('/\s+/', ' ', $td->filter('font:nth-child(1)')->html())));
                    switch ($key) {
                        case 'NOME EMPRESARIAL': $key = 'razao_social'; break;
                        case 'TÍTULO DO ESTABELECIMENTO (NOME DE FANTASIA)': $key = 'nome_fantasia'; break;
                        case 'CÓDIGO E DESCRIÇÃO DA ATIVIDADE ECONÔMICA PRINCIPAL': $key = 'cnae_principal'; break;
                        case 'CÓDIGO E DESCRIÇÃO DAS ATIVIDADES ECONÔMICAS SECUNDÁRIAS': $key = 'cnaes_secundario'; break;
                        case 'CÓDIGO E DESCRIÇÃO DA NATUREZA JURÍDICA' : $key = 'natureza_juridica'; break;
                        case 'LOGRADOURO': $key = 'logradouro'; break;
                        case 'NÚMERO': $key = 'numero'; break;
                        case 'COMPLEMENTO': $key = 'complemento'; break;
                        case 'CEP': $key = 'cep'; break;
                        case 'BAIRRO/DISTRITO': $key = 'bairro'; break;
                        case 'MUNICÍPIO': $key = 'cidade'; break;
                        case 'UF': $key = 'uf'; break;
                        case 'SITUAÇÃO CADASTRAL': $key = 'situacao_cadastral'; break;
                        case 'DATA DA SITUAÇÃO CADASTRAL': $key = 'situacao_cadastral_data'; break;
                        case 'MOTIVO DE SITUAÇÃO CADASTRAL': $key = 'motivo_situacao_cadastral'; break;
                        case 'SITUAÇÃO ESPECIAL': $key = 'situacao_especial'; break;
                        case 'DATA DA SITUAÇÃO ESPECIAL': $key = 'situacao_especial_data'; break;
                        case 'TELEFONE': $key = 'telefone'; break;
                        case 'ENDEREÇO ELETRÔNICO': $key = 'email'; break;
                        case 'ENTE FEDERATIVO RESPONSÁVEL (EFR)': $key = 'ente_federativo_responsavel'; break;
                        case 'DATA DE ABERTURA': $key = 'data_abertura'; break;
                        default: $key = null; break;
                    }
                    if(!is_null($key)){
                        $bs = $td->filter('font > b');
                        foreach ($bs as $b){
                            $b = new Crawler($b);
                            $str = trim(preg_replace('/\s+/', ' ', $b->html()));
                            $attach = htmlspecialchars_decode($str);
                            if($bs->count() == 1){
                                $result[$key] = $attach;
                            }else{
                                $result[$key][] = $attach;
                            }
                        }
                    }
                }
            }
            if(isset($result['telefone']) && $result['telefone'] != ''){
                $posBarra = strpos($result['telefone'], '/');
                if($posBarra > 0){
                    $result['telefone2'] = substr($result['telefone'], $posBarra + 1, strlen($result['telefone']) - $posBarra);
                    $result['telefone'] = substr($result['telefone'], 0, $posBarra - 1);
                }
            }
            $result['code'] = 0;//adicionei pra informar que não há exception.
        }
        return $result;
    }

    /**
     * Send request
     *
     * @param $uri
     * @param array $data
     * @param array $headers
     *
     * @return array
     */
    private static function request($uri, array $data = [], array $headers = []){
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $uri,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            //CURLOPT_TIMEOUT_MS     => 30000
        ]);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $response = curl_exec($curl);
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        $headers = [];
        foreach(explode(PHP_EOL, substr($response, 0, $size)) as $i){
            $t = explode(':', $i, 2);
            if(isset($t[1])){
                $headers[trim($t[0])] = trim($t[1]);
            }
        }
        $response = substr($response, $size);
        return compact('response', 'headers');
    }
}