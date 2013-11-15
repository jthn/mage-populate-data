#!/bin/bash

i=0

finish()
{
	echo "Exit!"
	exit
}
trap finish SIGINT

while :; do
	(( ++i ))
	php populate.php $i
done


