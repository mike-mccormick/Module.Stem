<?php

namespace Rhubarb\Stem\Tests\unit\Collections;

use Rhubarb\Stem\Collections\RepositoryCollection;
use Rhubarb\Stem\Filters\Equals;
use Rhubarb\Stem\Tests\unit\Fixtures\Company;
use Rhubarb\Stem\Tests\unit\Fixtures\Example;
use Rhubarb\Stem\Tests\unit\Fixtures\ModelUnitTestCase;

class RepositoryCollectionTest extends ModelUnitTestCase
{
    public function testCollectionFilters()
    {
        $this->setupData();

        $collection = new RepositoryCollection(Example::class);

        $this->assertCount(4, $collection);

        $collection->filter(new Equals("Forename", "John"));

        $this->assertCount(2, $collection);

        $collection = new RepositoryCollection(Example::class);
        $collection->intersectWith(Company::find(new Equals("CompanyID", 2)), "CompanyID", "CompanyID");

        $this->assertCount(1, $collection);
        $this->assertEquals("Mary", $collection[0]->Forename);
    }

    private function setupData()
    {
        $company = new Company();
        $company->CompanyName = "C1";
        $company->Balance = 1;
        $company->save();

        $company = new Company();
        $company->CompanyName = "C2";
        $company->Balance = 2;
        $company->save();

        $company = new Company();
        $company->CompanyName = "C3";
        $company->Balance = 3;
        $company->save();

        $contact = new Example();
        $contact->Forename = "John";
        $contact->CompanyID = 1;
        $contact->save();

        $contact = new Example();
        $contact->Forename = "Mary";
        $contact->CompanyID = 2;
        $contact->save();

        $contact = new Example();
        $contact->Forename = "Jule";
        $contact->CompanyID = 3;
        $contact->save();

        $contact = new Example();
        $contact->Forename = "John";
        $contact->CompanyID = 1;
        $contact->save();
    }
}