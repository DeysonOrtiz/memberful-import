# memberful-import
Import users from Memberful exported list to EDD


This script ( once completed ) will allow EDD users to import customers from Memberful.
The script is run by adding ?mmt-memberful-import to the site's url.
This plugin looks the file: /memberful.json inside of the plugin folder with the list of Members.

Below is the message I got from Pippin.  It seems that the customer information is not being written into EDD’s customer records:

“ The EDD customer records are created automatically when the purchase record (using edd_insert_payment() function) is created. EDD does a check when that function runs to see if a customer already exists; if it doesn't, one is created.
So all the developer needs to do is create the purchase record for the customers and they will have a customer record created. “

Here is a description of the different kind of customers I have.  

- Yearly Subscriber -  I have one “All Access Pass - Recurring” Product which is an EDD product with all my products bundled in it.  I use the Recurring EDD Plugin to make a membership subscription product.

- Lifetime Member - This member gets access to the “All Access Pass - Recurring” EDD Product bundle which give them access to all my products.  They will never pay anything again and never expire.

- Yearly Member- This member gets access to the “All Access Pass” EDD Product bundle.  They will pay on a yearly recurring basis.  Some may have canceled the auto renew payment processing so it will be set to “false” if they did. Also if their membership is expired I want to keep the customer in the EDD database in case they sign up again the original price they originally paid will be used since the membership price is supposed to be grandfathered in.

- Individual purchases.  These are customers that purchase individual products.  They are not members and no recurring payments.  They will have access to the individual products they have purchased. Some individual purchase customers eventually have become yearly customers.
