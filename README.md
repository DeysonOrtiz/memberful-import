# memberful-import
Import users from Memberful exported list to EDD


This script ( once completed ) will allow EDD users to import customers from Memberful.
The script is run by adding ?mmt-memberful-import to the site's url.


Here is the latest messages:

Below is the message I got from Pippin.  It seems that the customer information is not being written into EDD’s customer records:

“ The EDD customer records are created automatically when the purchase record (using edd_insert_payment() function) is created. EDD does a check when that function runs to see if a customer already exists; if it doesn't, one is created.
So all the developer needs to do is create the purchase record for the customers and they will have a customer record created. “
