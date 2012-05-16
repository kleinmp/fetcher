<?php

/**
 * @file
 *  Provides authentication
 */

namespace Ignition\Authentication;
use Ignition\Exception\IgnitionException;
use Symfony\Component\Process\Process;

class OpenSshKeys implements \Ignition\Authentication\AuthenticationInterface {

  private $container = NULL;

  public function __construct(\Pimple $container) {
    $this->container = $container;
  }

  /**
   * Recieve a client object similar to \Ignition\Utility\HTTPClient() and add authentication parameters.
   *
   * @param $client
   *   An HTTPClient descended object.
   * @return Void
   */
  public function addAuthenticationToHTTPClientFromDrushContext(\Ignition\Utility\HTTPClient $client) {

    // TODO: Allow the specification of the key to use.

    // Generate pseudo random noise to sign for this transaction.
    // The current time is included preventing replay attacks.
    $text = $this->container['random'](64) . '-' . time();
    $signature = $this->getSignature($text);
    $keyData = $this->parsePublicKey($this->getPublicKey());
    $fingerprint = $keyData['fingerprint'];

    $client->addParam('ssh_plaintext', base64_encode($text));
    $client->addParam('ssh_signature', base64_encode($signature));
    $client->addParam('ssh_fingerprint', base64_encode($fingerprint));

  }

  /**
   * Load the plaintext public key.
   *
   * @return
   *   A string of the OpenSSH formatted public key.
   */
  public function getPublicKey() {
    // TODO: Make this smarter/configurable.
    $system = $this->container['system'];
    $home_folder = $system->getUserHomeFolder();
    return file_get_contents($home_folder . '/.ssh/id_rsa.pub');
  }

   /**
    * Parses a SSH public key generating the fingerprint for an OpenSSH formatted public key.
    *
    * This method taken from the sshkey module http://drupal.org/project/sshkey.
    *
    * @param string $keyRaw
    *   The string with the raw SSH public key.
    */
  public function parsePublicKey($keyRaw) {
     $parsed['value'] = trim(preg_replace('/\s+/', ' ', $keyRaw));

     // The SSH key should be a string in the form:
     // "<algorithm type> <base64-encoded key> <comment>"
     $keyParts = explode(' ', $parsed['value'], 3);
     if (count($keyParts) < 2) {
       throw new IgnitionException(dt('The key is invalid.'));
     }

     $parsed['algorithm'] = $keyParts[0];
     if (!in_array($parsed['algorithm'], array('ssh-rsa', 'ssh-dss'))) {
       throw new IgnitionException(dt("The key is invalid. It must begin with <em>ssh-rsa</em> or <em>ssh-dss</em>."));
     }

     $parsed['key'] = $keyParts[1];
     $keyBase64Decoded = base64_decode($parsed['key']);
     if ($keyBase64Decoded === FALSE) {
       throw new IgnitionException(dt('The key could not be decoded.'));
     }
     $parsed['fingerprint'] = md5($keyBase64Decoded);

     if (isset($keyParts[2])) {
       $parsed['comment'] = $keyParts[2];
     }

     return $parsed;
  }

  /**
   * Sign some text and return the signature.
   */
  public function getSignature($text) {
    if ($this->sshAgentExists() && $signature = $this->getSignatureFromSSHAgent()) {
      return $signature;
    }
    else {
      // Load the private key in .pem format.
      // TODO: Make this smarter/configurable.
      $process = new Process('openssl rsa -in ' . $this->container['system']->getUserHomeFolder() . '/.ssh/id_rsa');
      $process->setTimeout(3600);
      $process->run();
      if (!$process->isSuccessful()) {
        throw new \RuntimeException($process->getErrorOutput());
      }
      $pemText = $process->getOutput();
      $privateKeyId = openssl_get_privatekey($pemText);
      // TODO: Dynamically generate the source to prevent replay attacks.
      $signature = '';
      openssl_sign($text, $signature, $privateKeyId);
      return $signature;
    }
  }

  /**
   * Check to see whether ssh-agent is running on the system.
   */
  public function sshAgentExists() {
    // TODO: Change this whet getSignatureFromSSHAgent() works.
    return FALSE;
  }

  /**
   *
   */
  public function getSignatureFromSSHAgent() {
    // TODO: This is a start at getting ssh-agent to do the signing for us.
    // Finish it to prevent password mutiny.
    // This is based on http://ptspts.blogspot.com/2010/06/how-to-use-ssh-agent-programmatically.html

    /*
    // Attempt 1:
    $address = getenv('SSH_AUTH_SOCK');
    //$fp = fsockopen($address, 80, $errno, $errstr, 30);
    $fp = stream_socket_client('unix://' . $address, $errno, $errstr, 5);
    if (!$fp) {
        drush_print("$errstr ($errno)<br />\n");
        drush_print('fail');
    }
    else {
        drush_print('success');
        $message = "\0\0\0\1\v";
        fwrite($fp, $message);
        $result = array();
        while (!feof($fp)) {
          $result[] = fgets($fp, 128);
        }
        fclose($fp);
        drush_print_r($result);
    }
    //*/

    /*/
    // Attempt 2:
    $address = getenv('SSH_AUTH_SOCK');
    if ($address !== FALSE) {
      $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
      $connction = socket_connect($socket, $address);
      $message = "\0\0\0\1\v";
      $result = socket_send($socket, $message, strlen($message), MSG_EOR);
      $write  = $except = $read = array($socket);
      // For now we grab everything from the socket, this is the max length.
      $result = socket_read($socket, 133693415);
      // The response string comes back with is binary.  We need to unpack it.
      $result = unpack('N', $result);
      if (($position = strpos($result, "\0\0\3\5\f")) !== FALSE) {
        drush_print($position);
        drush_print('contained');
      }
      else {
        drush_print('not contained');
      }
      $result ? drush_print_r($result) : drush_print('something failed');
    }
    //*/
  }
}