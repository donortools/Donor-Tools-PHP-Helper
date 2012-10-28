<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Helper to interact with the donortools.com API
 * 
 * In our application, we have donations that are made online, and others entered offline. 
 * We need to keep both the db on the webserver and the donortools db in synch
 * 
 * import_donations grabs all the offline ones that aren't in our db
 * save_donation saves a donation made on the site to donortools
 * 
 * @author Andrew Buzzell buzz@netgrowth.ca
 * @author Brett Meyer brett@3riverdev.com
 */

class DonorTools {

	/**
	 * Grab all the donations for the last 30 days (that is the default from donor tools) and throw them in our db if we don't have them
	 * 
	 * 
	 */
	static function import_donations()
	{
		// load people and donations from the API
		$donations 	= self::get_xml_from_api('donations.xml');
		$personas 	= self::get_xml_from_api('personas.xml');
		
		$import_count = 0;
		// iterate through them, saving any we don't already have
		foreach ($donations->donation as $donation)
		{
			$donation_id = $donation->{'id'};
			$existing_donation = ORM::factory('Donation')->where('dt_donation_id', $donation_id)->find();
			if (!$existing_donation->loaded)
			{
				$import_count++;
				$new_donation = ORM::factory('Donation');

				// if its in donortools, and NOT in our db, then it was entered offline (ie was not an online donation)			
				$new_donation->type 				= 'offline'; 
				$new_donation->status 				= 'confirmed';
				$new_donation->dt_donation_id 	= $donation_id;
				$new_donation->donation 			= ($donation->{'amount-in-cents'}/100);
				$new_donation->created_time		= strtotime($donation->{'received-on'});
				
				// access the corresponding persona node
				$person = $personas->xpath("persona/id['$donation_id']/parent::*"); 
				if (!$person) 
				{
					Kohana::log('error', "unable to retrieve person for donation: $donation_id");
				}

				$new_donation->dt_persona_id 	= $person[0]->id;
				$new_donation->first_name 		= (string) $person[0]->names->name->{'first-name'};
				$new_donation->last_name 		= (string) $person[0]->names->name->{'last-name'};
				$new_donation->company 			= (string) $person[0]->{'company-name'};
				$new_donation->email 				= (string) $person[0]->{'email-addresses'}->{'email-address'}->{'email-address'};
				$new_donation->city 					= (string) $person[0]->{'addresses'}->{'address'}->{'city'};
				$new_donation->region_text 		= (string) $person[0]->{'addresses'}->{'address'}->{'state'};
				$new_donation->address 			= (string) $person[0]->{'addresses'}->{'address'}->{'street-address'};
				$new_donation->postal_code 		= (string) $person[0]->{'addresses'}->{'address'}->{'postal-code'};
				
				$new_donation->save();
			}
		}
		return $import_count;
	}
	
	/**
	 * Save a donation made online to donortools
	 *
	 */
	static function save_donation($config, $donation_object, $persona_id=FALSE)
	{
		if (!$persona_id) {
			$person = new SimpleXMLElement('<persona></persona>');
			$person->addChild('names');
			$person->{'names'}->addAttribute('type', 'array');
			$person->{'names'}->addChild('name');
			$person->{'names'}->{'name'}->addChild('first-name', $donation_object->first_name);
			$person->{'names'}->{'name'}->addChild('last-name', $donation_object->last_name);
	
			$person->addChild('addresses');
			$person->{'addresses'}->addAttribute('type', 'array');
			$person->{'addresses'}->addChild('address');
			$person->{'addresses'}->{'address'}->addChild('city', $donation_object->city);
			$person->{'addresses'}->{'address'}->addChild('street-address', $donation_object->address);
			$person->{'addresses'}->{'address'}->addChild('state', $donation_object->region_text);
			$person->{'addresses'}->{'address'}->addChild('postal-code', $donation_object->postal_code);
	
			$person->addChild('email-addresses');
			$person->{'email-addresses'}->addAttribute('type', 'array');
			$person->{'email-addresses'}->addChild('email-address');
			$person->{'email-addresses'}->{'email-address'}->addChild('email-address', $donation_object->email);
	
			$response = get_xml_from_api($config, 'personas.xml', $person->asXML());
			$persona_id = ((string) $response[0]->{'id'});
		}
	
		$donation = new SimpleXMLElement("<donation></donation>");
		$donation->addChild('donation-type-id', 14);
		$donation->{'donation-type-id'}->addAttribute('type', 'integer');
		$donation->addChild('persona-id', $persona_id);
		$donation->{'persona-id'}->addAttribute('type', 'integer');
	
		$donation->addChild('splits');
		$donation->{'splits'}->addAttribute('type', 'array');
		$donation->{'splits'}->addChild('split');
	
		$donation->{'splits'}->{'split'}->addChild('amount-in-cents', ($donation_object->donation * 100));
		$donation->{'splits'}->{'split'}->{'amount-in-cents'}->addAttribute('type', 'integer');
	
		$donation->{'splits'}->{'split'}->addChild('fund-id', $config['donortools_fund_id']);
		$donation->{'splits'}->{'split'}->{'fund-id'}->addAttribute('type', 'integer');
	
		$donation->addChild('source-id', $config['donortools_source_id']);
		$donation->{'source-id'}->addAttribute('type', 'integer');
	
		$response = get_xml_from_api($config, 'donations.xml', $donation->asXML());
		$donation_id = ((string) $response[0]->{'id'});
	
		return array ('persona_id' => $persona_id, 'donation_id' => $donation_id);
	}
	
	
	/**
	 * Communicate with the donortools API. Expects a config file to specify user/pass and endpoint URL
	 *
	 * Executes a GET if no $post_xml is provided, otherwise posts
	 * 
	 */
	static function get_xml_from_api($config, $target, $post_xml = FALSE)
	{
		$target_url = $config['donortools_endpoint'].'/'.$target;
	
		$ch = curl_init ($target_url);
		
		if ($post_xml) {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, "$post_xml");
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));
		}
	
		curl_setopt($ch, CURLOPT_USERPWD, $config['donortools_username'] . ':' . $config['donortools_password']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		$response = curl_exec ($ch);
		
		if (curl_errno($ch) > 0) {
			echo "unable to curl to api";
		} else  {
			curl_close($ch);
		}
		
		$xml = new SimpleXMLElement($response);
		return $xml;
	}
}