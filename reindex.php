<?php
	/* Solr Connectivity */
	define('SOLR_HOST','http://0.0.0.0');		/* Hostname */
	define('SOLR_PORT','8983');						/* Port */
	define('SOLR_PATH','/solr/');					/* Default Solr path (usually /solr/) */
	define('SOLR_CORE','my_core/');					/* Core */
	define('SOLR_WRITER_TYPE','json');				/* Writer Type - output type.  Currently only 'json' is supported. */
	
	/* Performance Options */
	define('PAGINATE_ROWS',20);						/* Number of docs to show per page */
	define('COMMIT_FREQUENCY',100);					/* How many pages to commit after. */
	define('CURL_TIMEOUT',60);						/* How long until curl request times out.  Set to 0 for unlimited. */
	
	/* Re-Indexing Options */
	define('QUERY','*:*');							/* Initial query to use for searching/re-indexing */
	define('STARTINDEX',0);							/* Index to start searching/re-indexing from */
	define('ENDINDEX',0);							/* Index to end searching/re-indexing.  Set to 0 for no end index. */
	
	/* Query Parameters for search query */
	$params = array(
		'q' => QUERY,
		'start' => STARTINDEX,
		'rows' => PAGINATE_ROWS
	);
	
	/* Ignore Fields
		Format: 'field_name' => 'operation' (empty,ignore)
		Operations:
			empty - sets a blank value for the field ''
			ignore - does not re-post this fields data	
	*/
	$ignore_fields = array(
		
	);
		
	/**********************************************************/
	// Class definitions - edit at your own risk
	/**********************************************************/
	
	class Solr {
		
		/**
	     *  executes a get query on a solr database
	     * 
	     *  @param array $query - associative array (key/value) of query string variables
	     *  @return array $data - solr data array
	     *  @throws none
	     */
		public static function get($query,$retry=null) {
			$ch = self::init_curl();	/* Initialize curl */

			//Set output type
			$query['wt'] = SOLR_WRITER_TYPE;

			//Set URL
			$url = SOLR_HOST . SOLR_PATH . SOLR_CORE . 'select?' . http_build_query($query);

			curl_setopt($ch, CURLOPT_URL, $url);
			
			$data = self::transform(curl_exec($ch));
			
			if(count($data['response']['docs']) > 0) {
				return $data;
			} else {
				//Sleep and re-try once
				if($retry !== true) {
					sleep(5);
					self::get($query,true);
				}				
				return false;
			}
		}

		/**
	     *  posts data to a solr database and optionally commits data
	     * 
	     *  @param array $data - associative (key/value pair) array of solr data
	     *  @return array $response - solr response array
	     *  @throws none
	     */
		public static function post($data) {
			$ch = self::init_curl();	/* Initialize curl */
			
			//Set update URL
			$url = SOLR_HOST . SOLR_PATH . SOLR_CORE . 'update/json';
			curl_setopt($ch, CURLOPT_URL, $url);
			
			//Configure curl for post
			curl_setopt($ch,CURLOPT_POSTFIELDS, json_encode($data));
			
			curl_setopt($ch,CURLOPT_POST,true);
			
			curl_setopt($ch,CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json'
			));
			
			//Execute post
			$solr_response = simplexml_load_string(curl_exec($ch));
						
			return (int) $solr_response->lst->int[0];
		}

		/**
	     *  sends a commit statement to solr
	     * 
	     *  @param none
	     *  @return array $response - solr response array
	     *  @throws none
	     */
		public static function commit() {
			$ch = self::init_curl();	/* Initialize curl */

			$url = SOLR_HOST . SOLR_PATH . SOLR_CORE . 'update?commit=true';
			curl_setopt($ch, CURLOPT_URL, $url);
			
			curl_setopt($ch,CURLOPT_POST,true);
			
			//Execute post
			$solr_response = simplexml_load_string(curl_exec($ch));
						
			return (int) $solr_response->lst->int[0];
		}
		
		/**
	     *  sends an optimize statement to solr
	     * 
	     *  @param none
	     *  @return array $response - solr response array
	     *  @throws none
	     */
		public static function optimize() {
			$ch = init_curl();	/* Initialize curl */

			$url = SOLR_HOST . SOLR_PATH . SOLR_CORE . '?optimize=true';
			curl_setopt($ch, CURLOPT_URL, $url);
		}
		
		/**
	     *  transforms raw solr responses into associative array
	     * 
	     *  @param string $data - raw, unprocessed, solr data
	     *  @return array $response - associative array representation of raw solr response data
	     *  @throws Exception - unsupported output format
	     */
		public static function transform($data) {
			switch(SOLR_WRITER_TYPE) {
				case 'json':
					return json_decode($data,true);
				default:
					throw new Exception('Unsupported JSON output format.  Check SOLR_WRITER_TYPE.');
			}
		}

		/**
	     *  initializes curl with default options
	     * 
	     *  @param none
	     *  @return resource $ch - curl handle
	     *  @throws none
	     */
		protected static function init_curl() {
			//Initialize CURL
			$ch = curl_init();

			//Set CURL Options to return results
			curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);

			//Follow up to two redirects
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 
			curl_setopt($ch, CURLOPT_MAXREDIRS, 2);

			//Set timeout so it doesn't run forever
			//5 seconds to make a connection
			//15 seconds for the whole transfer
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CURL_TIMEOUT);
			curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);

			//Do not verify SSL peer
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

			//Set connectivity port
			curl_setopt($ch, CURLOPT_PORT, SOLR_PORT);

			return $ch;
		}
	}
		
	/**********************************************************/
	// Procedural execution steps - edit at your own risk
	/**********************************************************/
	
	/* Main application loop */	
	$doc_cnt=$params['start'];
	$page_cnt=0;
	$total_docs=0;
	
	while(($data = Solr::get($params)) !== false) {
		$total_docs = $data['response']['numFound'];
		$page_cnt++;
		
		//Initialize solr wrapper
		$solr_array = array(
			'add' => array()
		);
		
		//Loop through returned documents
		foreach($data['response']['docs'] as $doc) {
			//Increment document count
			$doc_cnt++;
						
			//See if ENDINDEX has been reached
			$end_of_index = (ENDINDEX > 0 && $doc_cnt == ENDINDEX);
			
			//Remove un-wanted fields
			foreach($ignore_fields as $i_field=>$operation) {
				if(array_key_exists($i_field,$doc) === true) {
					switch($operation) {
						case 'empty':
							$doc[$i_field] = '';
							break;
						case 'ignore':
							unset($doc[$i_field]);
							break;
					}
				}
			}
						
			//Append data to solr array
			$solr_array['add'][] = array(
				'doc' => $doc
			);
			
			
			//Stop if we reached the end of the index
			if($end_of_index === true) {
				//Force next pagination request to end
				$params['start'] = $total_docs;
				break;
			}			
		} //foreach docs
		
		//Post page of data
		$response = Solr::post($solr_array);
		if($response !== 0) {
			echo "Error processing document ID: " . $solr_array['add']['doc']['id'] . "\.  Response: $response.\n";
		}
		
		//Commit page(s) of data
		if($page_cnt % COMMIT_FREQUENCY == 0 || $end_of_index) {
			$response = Solr::commit();
			
			if($response !== 0) {
				echo "Error committing data to Solr!  Response: $response.\n";
			} else {
				echo "Committed Data - $doc_cnt of $total_docs (" . date("c") . ") " . number_format(($doc_cnt/$total_docs)*100,4) . "% Complete.\n";
			}
		}
		
		//Increment
		$params['start'] += PAGINATE_ROWS;
	}
?>

                              Apache License
                        Version 2.0, January 2004
                     http://www.apache.org/licenses/

TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION

1. Definitions.

   "License" shall mean the terms and conditions for use, reproduction,
   and distribution as defined by Sections 1 through 9 of this document.

   "Licensor" shall mean the copyright owner or entity authorized by
   the copyright owner that is granting the License.

   "Legal Entity" shall mean the union of the acting entity and all
   other entities that control, are controlled by, or are under common
   control with that entity. For the purposes of this definition,
   "control" means (i) the power, direct or indirect, to cause the
   direction or management of such entity, whether by contract or
   otherwise, or (ii) ownership of fifty percent (50%) or more of the
   outstanding shares, or (iii) beneficial ownership of such entity.

   "You" (or "Your") shall mean an individual or Legal Entity
   exercising permissions granted by this License.

   "Source" form shall mean the preferred form for making modifications,
   including but not limited to software source code, documentation
   source, and configuration files.

   "Object" form shall mean any form resulting from mechanical
   transformation or translation of a Source form, including but
   not limited to compiled object code, generated documentation,
   and conversions to other media types.

   "Work" shall mean the work of authorship, whether in Source or
   Object form, made available under the License, as indicated by a
   copyright notice that is included in or attached to the work
   (an example is provided in the Appendix below).

   "Derivative Works" shall mean any work, whether in Source or Object
   form, that is based on (or derived from) the Work and for which the
   editorial revisions, annotations, elaborations, or other modifications
   represent, as a whole, an original work of authorship. For the purposes
   of this License, Derivative Works shall not include works that remain
   separable from, or merely link (or bind by name) to the interfaces of,
   the Work and Derivative Works thereof.

   "Contribution" shall mean any work of authorship, including
   the original version of the Work and any modifications or additions
   to that Work or Derivative Works thereof, that is intentionally
   submitted to Licensor for inclusion in the Work by the copyright owner
   or by an individual or Legal Entity authorized to submit on behalf of
   the copyright owner. For the purposes of this definition, "submitted"
   means any form of electronic, verbal, or written communication sent
   to the Licensor or its representatives, including but not limited to
   communication on electronic mailing lists, source code control systems,
   and issue tracking systems that are managed by, or on behalf of, the
   Licensor for the purpose of discussing and improving the Work, but
   excluding communication that is conspicuously marked or otherwise
   designated in writing by the copyright owner as "Not a Contribution."

   "Contributor" shall mean Licensor and any individual or Legal Entity
   on behalf of whom a Contribution has been received by Licensor and
   subsequently incorporated within the Work.

2. Grant of Copyright License. Subject to the terms and conditions of
   this License, each Contributor hereby grants to You a perpetual,
   worldwide, non-exclusive, no-charge, royalty-free, irrevocable
   copyright license to reproduce, prepare Derivative Works of,
   publicly display, publicly perform, sublicense, and distribute the
   Work and such Derivative Works in Source or Object form.

3. Grant of Patent License. Subject to the terms and conditions of
   this License, each Contributor hereby grants to You a perpetual,
   worldwide, non-exclusive, no-charge, royalty-free, irrevocable
   (except as stated in this section) patent license to make, have made,
   use, offer to sell, sell, import, and otherwise transfer the Work,
   where such license applies only to those patent claims licensable
   by such Contributor that are necessarily infringed by their
   Contribution(s) alone or by combination of their Contribution(s)
   with the Work to which such Contribution(s) was submitted. If You
   institute patent litigation against any entity (including a
   cross-claim or counterclaim in a lawsuit) alleging that the Work
   or a Contribution incorporated within the Work constitutes direct
   or contributory patent infringement, then any patent licenses
   granted to You under this License for that Work shall terminate
   as of the date such litigation is filed.

4. Redistribution. You may reproduce and distribute copies of the
   Work or Derivative Works thereof in any medium, with or without
   modifications, and in Source or Object form, provided that You
   meet the following conditions:

   (a) You must give any other recipients of the Work or
       Derivative Works a copy of this License; and

   (b) You must cause any modified files to carry prominent notices
       stating that You changed the files; and

   (c) You must retain, in the Source form of any Derivative Works
       that You distribute, all copyright, patent, trademark, and
       attribution notices from the Source form of the Work,
       excluding those notices that do not pertain to any part of
       the Derivative Works; and

   (d) If the Work includes a "NOTICE" text file as part of its
       distribution, then any Derivative Works that You distribute must
       include a readable copy of the attribution notices contained
       within such NOTICE file, excluding those notices that do not
       pertain to any part of the Derivative Works, in at least one
       of the following places: within a NOTICE text file distributed
       as part of the Derivative Works; within the Source form or
       documentation, if provided along with the Derivative Works; or,
       within a display generated by the Derivative Works, if and
       wherever such third-party notices normally appear. The contents
       of the NOTICE file are for informational purposes only and
       do not modify the License. You may add Your own attribution
       notices within Derivative Works that You distribute, alongside
       or as an addendum to the NOTICE text from the Work, provided
       that such additional attribution notices cannot be construed
       as modifying the License.

   You may add Your own copyright statement to Your modifications and
   may provide additional or different license terms and conditions
   for use, reproduction, or distribution of Your modifications, or
   for any such Derivative Works as a whole, provided Your use,
   reproduction, and distribution of the Work otherwise complies with
   the conditions stated in this License.

5. Submission of Contributions. Unless You explicitly state otherwise,
   any Contribution intentionally submitted for inclusion in the Work
   by You to the Licensor shall be under the terms and conditions of
   this License, without any additional terms or conditions.
   Notwithstanding the above, nothing herein shall supersede or modify
   the terms of any separate license agreement you may have executed
   with Licensor regarding such Contributions.

6. Trademarks. This License does not grant permission to use the trade
   names, trademarks, service marks, or product names of the Licensor,
   except as required for reasonable and customary use in describing the
   origin of the Work and reproducing the content of the NOTICE file.

7. Disclaimer of Warranty. Unless required by applicable law or
   agreed to in writing, Licensor provides the Work (and each
   Contributor provides its Contributions) on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
   implied, including, without limitation, any warranties or conditions
   of TITLE, NON-INFRINGEMENT, MERCHANTABILITY, or FITNESS FOR A
   PARTICULAR PURPOSE. You are solely responsible for determining the
   appropriateness of using or redistributing the Work and assume any
   risks associated with Your exercise of permissions under this License.

8. Limitation of Liability. In no event and under no legal theory,
   whether in tort (including negligence), contract, or otherwise,
   unless required by applicable law (such as deliberate and grossly
   negligent acts) or agreed to in writing, shall any Contributor be
   liable to You for damages, including any direct, indirect, special,
   incidental, or consequential damages of any character arising as a
   result of this License or out of the use or inability to use the
   Work (including but not limited to damages for loss of goodwill,
   work stoppage, computer failure or malfunction, or any and all
   other commercial damages or losses), even if such Contributor
   has been advised of the possibility of such damages.

9. Accepting Warranty or Additional Liability. While redistributing
   the Work or Derivative Works thereof, You may choose to offer,
   and charge a fee for, acceptance of support, warranty, indemnity,
   or other liability obligations and/or rights consistent with this
   License. However, in accepting such obligations, You may act only
   on Your own behalf and on Your sole responsibility, not on behalf
   of any other Contributor, and only if You agree to indemnify,
   defend, and hold each Contributor harmless for any liability
   incurred by, or claims asserted against, such Contributor by reason
   of your accepting any such warranty or additional liability.

END OF TERMS AND CONDITIONS

APPENDIX: How to apply the Apache License to your work.

   To apply the Apache License to your work, attach the following
   boilerplate notice, with the fields enclosed by brackets "[]"
   replaced with your own identifying information. (Don't include
   the brackets!)  The text should be enclosed in the appropriate
   comment syntax for the file format. We also recommend that a
   file or class name and description of purpose be included on the
   same "printed page" as the copyright notice for easier
   identification within third-party archives.

Copyright 2011 Jason Palmer - Toasted Snow

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
