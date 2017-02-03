# sm_inefax

Asterisk PBX AGI which handles incoming faxes, converts them to PDF, and mails them to the specified e-mail address.  Tested
  server on CentOS 6.x, Asterisk 11.x, PHP 5.3.x.  Tested Gmail w/Chrome 55+ and Thunderbird 45+ as clients.
    
# Pros
  * Generates a nice looking MIME email message.
  * Scaled down copy of the first page is shown inline with the HTML part of email
  * Doesn't require ImageMagik
  * Text version of message is included for people living in the dark ages
  
   
# Cons
   * Not much real world usage.
  
---  
Copyright (c) 2017 InterGlobe Communications, Inc.  Licensed under GNU Affero GPL 3  
Contact Gerald Bove <gbove@nyigc.com> for commercial licensing options.  
