<?php

/**
 * Helper to interact with the donortools.com API
 * 
 * import_donations grabs all the offline ones that aren't in our db
 * 
 * save_donation saves a donation made on the site to donortools
 * 
 * @author Andrew Buzzell buzz@netgrowth.ca
 * @author Brett Meyer brett@3riverdev.com
 */

class DonorTools {

	/**
	 * Grab all the donations for the last 30 days (the default from donor
	 * tools), parse the XML, and return a usable object.
	 * 
	 * @param $config
	 * 				$config['donortools_endpoint']
	 * 				$config['donortools_fund_id']
	 * 				$config['donortools_source_id']
	 * 				$config['donortools_username']
	 * 				$config['donortools_password']
	 * 
	 * $config can be modified as needed to match your application and/or
	 * framework.
	 * 
	 * @return $new_donations array of new donations
	 * 				$new_donation->donation_id
	 * 				$new_donation->donation (in USD, whole dollars)
	 * 				$new_donation->created_time (timestamp)
	 * 				$new_donation->persona_id
	 * 				$new_donation->first_name
	 * 				$new_donation->last_name
	 * 				$new_donation->company
	 * 				$new_donation->email
	 * 				$new_donation->address
	 * 				$new_donation->city
	 * 				$new_donation->region_text
	 * 				$new_donation->postal_code
	 */
	static function import_donations($config)
	{
		// load people and donations from the API
		$donations 	= self::get_xml_from_api($config, 'donations.xml');
		$personas 	= self::get_xml_from_api($config, 'personas.xml');
		
		$new_donations = array();
		
		foreach ($donations->donation as $donation)
		{
			$donation_id = (string) $donation->{'id'};
			$new_donation = new stdClass;

			$new_donation->donation_id = $donation_id;
			$new_donation->donation = ($donation->{'amount-in-cents'}/100);
			$new_donation->created_time = strtotime($donation->{'received-on'});
			
			// access the corresponding persona node
			$person = $personas->xpath("persona/id['$donation_id']/parent::*"); 
			if ($person) 
			{
				$new_donation->persona_id = (string) $person[0]->id;
				$new_donation->first_name = (string) $person[0]->names->name->{'first-name'};
				$new_donation->last_name = (string) $person[0]->names->name->{'last-name'};
				$new_donation->company = (string) $person[0]->{'company-name'};
				$new_donation->email = (string) $person[0]->{'email-addresses'}->{'email-address'}->{'email-address'};
				$new_donation->city = (string) $person[0]->{'addresses'}->{'address'}->{'city'};
				$new_donation->region_text = (string) $person[0]->{'addresses'}->{'address'}->{'state'};
				$new_donation->address = (string) $person[0]->{'addresses'}->{'address'}->{'street-address'};
				$new_donation->postal_code = (string) $person[0]->{'addresses'}->{'address'}->{'postal-code'};
			}
		
			$new_donations[] = $new_donation;
		}
		
		return $new_donations;
	}
	
	/**
	 * Save a donation made online to DonorTools.
	 * 
	 * @param $config
	 * 				$config['donortools_endpoint']
	 * 				$config['donortools_fund_id']
	 * 				$config['donortools_source_id']
	 * 				$config['donortools_username']
	 * 				$config['donortools_password']
	 * @param $donation_object
	 * 				$donation_object->first_name
	 * 				$donation_object->last_name
	 * 				$donation_object->city
	 * 				$donation_object->address
	 * 				$donation_object->region_text
	 * 				$donation_object->postal_code
	 * 				$donation_object->email
	 * 				$donation_object->donation (in USD, whole dollars)
	 * @param $persona_id (optional)
	 * 				If you store persona IDs in your app's db, pass it in here.
	 * 				If not given, creates a new persona.
	 * 
	 * $config & $donation_object can be modified as needed to match
	 * your application and/or framework.
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
	
			$response = self::get_xml_from_api($config, 'personas.xml', $person->asXML());
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
	
		$response = self::get_xml_from_api($config, 'donations.xml', $donation->asXML());
		$donation_id = ((string) $response[0]->{'id'});
	
		return array ('persona_id' => $persona_id, 'donation_id' => $donation_id);
	}
	
	
	private static function get_xml_from_api($config, $target, $post_xml = FALSE)
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

?>