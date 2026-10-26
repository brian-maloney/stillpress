<?php
/**
 * HTTP transport seam for remote deployers.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Aws;

/**
 * Sends a batch of fully built (already signed) requests and returns their responses.
 *
 * A request is array{method:string,url:string,headers:array<string,string>,body:string}. A response is
 * array{status:int,headers:array<string,string>,body:string,error:string}: header names are lowercase, and status is
 * 0 with a non-empty error when the request didn't complete. Responses keep the keys of their requests.
 */
interface Transport {

	/**
	 * Sends requests, possibly concurrently.
	 *
	 * @param array<int|string,array{method:string,url:string,headers:array<string,string>,body:string}> $requests Requests.
	 * @return array<int|string,array{status:int,headers:array<string,string>,body:string,error:string}>
	 */
	public function send( array $requests ): array;
}
