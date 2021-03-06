<?php

namespace Minter\SDK;

use InvalidArgumentException;
use Web3p\RLP\RLP;
use Minter\Library\ECDSA;
use Minter\Library\Helper;
use Minter\SDK\MinterCoins\{
    MinterDelegateTx, MinterRedeemCheckTx, MinterSellAllCoinTx, MinterSetCandidateOffTx, MinterSetCandidateOnTx, MinterCreateCoinTx, MinterDeclareCandidacyTx, MinterSendCoinTx, MinterUnboundTx, MinterSellCoinTx, MinterBuyCoinTx
};

/**
 * Class MinterTx
 * @package Minter\SDK
 */
class MinterTx
{
    /**
     * txData
     *
     * @var array
     */
    protected $tx;

    /**
     * @var RLP
     */
    protected $rlp;

    /**
     * Minter transaction structure
     *
     * @var array
     */
    protected $structure = [
        'nonce',
        'gasPrice',
        'gasCoin',
        'type',
        'data',
        'payload',
        'serviceData',
        'signatureType',
        'signatureData'
    ];

    /**
     * @var string
     */
    protected $txSigned;

    /**
     * Fee in PIP
     */
    const PAYLOAD_COMMISSION = 2;

    /**
     * All gas price multiplied by FEE DEFAULT (PIP)
     */
    const FEE_DEFAULT_MULTIPLIER = 1000000000000000;

    /**
     * Type of single signature for the transaction
     */
    const SIGNATURE_SINGLE_TYPE = 1;

    /**
     * Type of multi signature for the transaction
     */
    const SIGNATURE_MULTI_TYPE = 2;

    /**
     * MinterTx constructor.
     * @param $tx
     * @throws \Exception
     */
    public function __construct($tx)
    {
        $this->tx = $tx;
        $this->rlp = new RLP;

        if(is_string($tx)) {
            $this->txSigned = $tx;
            $this->tx = $this->decode($tx);
        }

        if(is_array($tx)) {
            $this->tx = $this->encode($tx);
        }
    }

    /**
     * Get
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $method = 'get' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], []);
        }

        return $this->tx[$name];
    }

    /**
     * Get sender Minter address
     *
     * @param array $tx
     * @return string
     * @throws \Exception
     */
    public function getSenderAddress(array $tx): string
    {
        return MinterWallet::getAddressFromPublicKey(
            $this->recoverPublicKey($tx)
        );
    }

    /**
     * Sign tx
     *
     * @param string $privateKey
     * @return string
     * @throws \Exception
     */
    public function sign(string $privateKey): string
    {
        if(!is_array($this->tx)) {
            throw new \Exception('Undefined transaction');
        }

        // encode data array to RPL
        $tx = $this->txDataRlpEncode($this->tx);

        // create kessak hash from transaction
        $keccak = Helper::createKeccakHash(
            $this->rlp->encode($tx)->toString('hex')
        );

        // prepare special V R S bytes and add them to transaction
        $tx['signatureData'] = $this->rlp->encode(
            ECDSA::sign($keccak, $privateKey)
        );

        $this->txSigned = $this->rlp->encode($tx)->toString('hex');

        return $this->txSigned;
    }

    /**
     * Recover public key
     *
     * @param array $tx
     * @return string
     * @throws \Exception
     */
    public function recoverPublicKey(array $tx): string
    {
        // prepare short transaction
        $shortTx = array_diff_key($tx, ['signatureData' => '']);
        $shortTx = Helper::hex2binRecursive($shortTx);
        $shortTx = $this->txDataRlpEncode($shortTx);

        // create kessak hash from transaction
        $msg = Helper::createKeccakHash(
            $this->rlp->encode($shortTx)->toString('hex')
        );

        // recover public key
        $signature = $tx['signatureData'];
        $publicKey = ECDSA::recover($msg, $signature['r'], $signature['s'], $signature['v']);

        return MinterPrefix::PUBLIC_KEY . $publicKey;
    }

    /**
     * Get hash of transaction
     *
     * @return string
     */
    public function getHash(): string
    {
        if(!$this->txSigned) {
            throw new \Exception('You need to sign transaction before');
        }

        // create SHA256 of tx
        $tx = hash('sha256', hex2bin($this->txSigned));

        // return first 40 symbols
        return MinterPrefix::TRANSACTION . substr($tx, 0, 40);
    }

    /**
     * Get fee of transaction in PIP
     *
     * @return string
     * @throws \Exception
     */
    public function getFee(): string
    {
        switch ($this->type) {
            case MinterSendCoinTx::TYPE:
                $gas = MinterSendCoinTx::COMMISSION;
                break;

            case MinterSellCoinTx::TYPE:
                $gas = MinterSellCoinTx::COMMISSION;
                break;

            case MinterSellAllCoinTx::TYPE:
                $gas = MinterSellAllCoinTx::COMMISSION;
                break;

            case MinterBuyCoinTx::TYPE:
                $gas = MinterBuyCoinTx::COMMISSION;
                break;

            case MinterCreateCoinTx::TYPE:
                $gas = MinterCreateCoinTx::COMMISSION;
                break;

            case MinterDeclareCandidacyTx::TYPE:
                $gas = MinterDeclareCandidacyTx::COMMISSION;
                break;

            case MinterDelegateTx::TYPE:
                $gas = MinterDelegateTx::COMMISSION;
                break;

            case MinterUnboundTx::TYPE:
                $gas = MinterUnboundTx::COMMISSION;
                break;

            case MinterRedeemCheckTx::TYPE:
                $gas = MinterRedeemCheckTx::COMMISSION;
                break;

            case MinterSetCandidateOnTx::TYPE:
                $gas = MinterSetCandidateOnTx::COMMISSION;
                break;

            case MinterSetCandidateOffTx::TYPE:
                $gas = MinterSetCandidateOffTx::COMMISSION;
                break;

            default:
                throw new \Exception('Unknown transaction type');
                break;
        }

        // multiplied gas price
        $gasPrice = bcmul($gas, self::FEE_DEFAULT_MULTIPLIER, 0);

        // commission for payload and serviceData bytes
        $commission = bcadd(
            strlen($this->payload) * bcmul(self::PAYLOAD_COMMISSION, self::FEE_DEFAULT_MULTIPLIER, 0),
            strlen($this->serviceData) * bcmul(self::PAYLOAD_COMMISSION, self::FEE_DEFAULT_MULTIPLIER, 0)
        );

        return bcadd($gasPrice, $commission, 0);
    }

    /**
     * Decode tx
     *
     * @param string $tx
     * @return array
     * @throws \Exception
     */
    protected function decode(string $tx): array
    {
        // pack RLP to hex string
        $tx = $this->rlpToHex($tx);

        // pack data of transaction to hex string
        $tx[4] = $this->rlpToHex($tx[4]);
        $tx[8] = $this->rlpToHex($tx[8]);

        // encode transaction data
        return $this->encode($this->prepareResult($tx), true);
    }

    /**
     * Encode transaction data
     *
     * @param array $tx
     * @param bool $isHexFormat
     * @return array
     * @throws \Exception
     */
    protected function encode(array $tx, bool $isHexFormat = false): array
    {
        $this->validateTx($tx);

        switch ($tx['type']) {
            case MinterSendCoinTx::TYPE:
                $dataTx = new MinterSendCoinTx($tx['data'], $isHexFormat);
                break;

            case MinterSellCoinTx::TYPE:
                $dataTx = new MinterSellCoinTx($tx['data'], $isHexFormat);
                break;

            case MinterSellAllCoinTx::TYPE:
                $dataTx = new MinterSellAllCoinTx($tx['data'], $isHexFormat);
                break;

            case MinterBuyCoinTx::TYPE:
                $dataTx = new MinterBuyCoinTx($tx['data'], $isHexFormat);
                break;

            case MinterCreateCoinTx::TYPE:
                $dataTx = new MinterCreateCoinTx($tx['data'], $isHexFormat);
                break;

            case MinterDeclareCandidacyTx::TYPE:
                $dataTx = new MinterDeclareCandidacyTx($tx['data'], $isHexFormat);
                break;

            case MinterDelegateTx::TYPE:
                $dataTx = new MinterDelegateTx($tx['data'], $isHexFormat);
                break;

            case MinterUnboundTx::TYPE:
                $dataTx = new MinterUnboundTx($tx['data'], $isHexFormat);
                break;

            case MinterRedeemCheckTx::TYPE:
                $dataTx = new MinterRedeemCheckTx($tx['data'], $isHexFormat);
                break;

            case MinterSetCandidateOnTx::TYPE:
                $dataTx = new MinterSetCandidateOnTx($tx['data'], $isHexFormat);
                break;

            case MinterSetCandidateOffTx::TYPE:
                $dataTx = new MinterSetCandidateOffTx($tx['data'], $isHexFormat);
                break;

            default:
                throw new \Exception('Unknown transaction type');
                break;
        }

        $tx['data'] = $dataTx->data;

        return $tx;
    }

    /**
     * Prepare output result
     *
     * @param array $tx
     * @return array
     * @throws \Exception
     */
    protected function prepareResult(array $tx): array
    {
        $result = [];
        foreach($this->structure as $key => $field) {
            if($field === 'data') {
                $result[$field] = $tx[$key];
            }
            elseif($field === 'payload' || $field === 'serviceData') {
                $result[$field] = Helper::pack2hex($tx[$key]);
            }
            elseif($field === 'gasCoin') {
                $result[$field] = MinterConverter::convertCoinName(
                    Helper::pack2hex($tx[$key])
                );
            }
            elseif($field === 'signatureData') {
                $result[$field] = [
                    'v' => hexdec($tx[$key][0]),
                    'r' => $tx[$key][1],
                    's' => $tx[$key][2]
                ];
            }
            else {
                $result[$field] = hexdec($tx[$key]);
            }
        }

        $result['from'] = $this->getSenderAddress($result);

        return $result;
    }

    /**
     * Convert array items from rlp to hex
     *
     * @param string $data
     * @return array
     */
    protected function rlpToHex(string $data): array
    {
        $data = $this->rlp->decode('0x' . $data);

        foreach ($data as $key => $value) {
            $data[$key] = $value->toString('hex');
        }

        return (array) $data;
    }

    /**
     * Convert tx data to rlp
     *
     * @param array $tx
     * @return array
     */
    protected function txDataRlpEncode(array $tx): array
    {
        $tx['gasCoin'] = MinterConverter::convertCoinName($tx['gasCoin']);
        $tx['data'] = $this->rlp->encode($tx['data']);

        return $tx;
    }

    /**
     * Validate transaction structure
     *
     * @param array $tx
     */
    protected function validateTx(array $tx): void
    {
        // get keys of tx and prepare structure keys
        $length = count($this->structure) - 1;
        $tx = array_slice(array_keys($tx), 0, $length);
        $structure = array_slice($this->structure, 0, $length);

        // compare
        if(!empty(array_diff_key($tx, $structure))) {
            throw new InvalidArgumentException('Invalid transaction structure params');
        }
    }
}