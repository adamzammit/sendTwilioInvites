# sendTwilioMessages

A Limesurvey Plugin that adds the option to send survey invitations and reminders via SMS and MMS using the Twilio API

## The Purpose

Send invitations and reminders to LimeSurvey participants using the Twilio API from the LimeSurvey participants messaging function

## Getting Started

### Prerequisites

* Limesurvey Version 3.x
* An account with Twilio
* An additional attribute in your participant table that has a mobile/cell number (must be in the international format, eg: +1555123456)

### Plugin Setup

Set the auth token and SID from your Twilio account in the general configuration plugin settings

Then set your message text and what attributes to choose as your MMS or SMS number from the plugin settings at the survey level.

### Installation

Download the zip from the [releases](https://github.com/adamzammit/sendTwilioMessages/releases) page and extract to your plugins folder. You can also clone directly from git: go to your plugins directory and type
```
git clone https://github.com/adamzammit/sendTwilioMessages.git sendTwilioMessages
```

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

## Acknowledgments

* Stefan Verweij – [Creating Limesurvey Plugins](https://medium.com/@evently/creating-limesurvey-plugins-adcdf8d7e334)
* https://github.com/stfandrade/sendTwilioInvites
* https://github.com/MZeit/SendSMSInvites
